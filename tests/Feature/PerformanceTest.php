<?php

use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Painel de desempenho dos técnicos (RF-25): acesso, período, escopo, uma contagem por chamado,
 * SLA oficial (pausas, reaberturas, violações históricas) e ausência de dados sem valores inventados.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));

    $this->manager = User::factory()->withRole('manager')->create();
    $this->admin = User::factory()->withRole('admin')->create();
    $this->ana = User::factory()->withRole('technician')->create(['name' => 'Ana Técnica']);
    $this->bruno = User::factory()->withRole('technician')->create(['name' => 'Bruno Técnico']);
    $this->policy = SlaPolicy::factory()->create(['priority' => 'medium', 'first_response_minutes' => 30, 'resolution_minutes' => 480]);
});

/** Chamado com política aplicada (SLA apurado) e prazos gravados a partir da abertura. */
function perfTicket(array $attributes = []): Ticket
{
    $createdAt = Carbon::parse($attributes['created_at'] ?? '2026-10-20 09:00:00');

    return Ticket::factory()->create([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'status' => TicketStatus::InProgress,
        'sla_policy_id' => test()->policy->id,
        'sla_first_response_minutes' => 30,
        'sla_resolution_minutes' => 480,
        'first_response_due_at' => $createdAt->copy()->addMinutes(30),
        'resolution_due_at' => $createdAt->copy()->addMinutes(480),
        ...$attributes,
    ]);
}

/** Resolução registrada como o fluxo real faz: evento de resolução com o autor. */
function perfResolve(Ticket $ticket, User $author, string $at, ?int $effective = 3600): Ticket
{
    $ticket->forceFill([
        'status' => TicketStatus::Resolved,
        'resolved_at' => Carbon::parse($at),
        'resolution_summary' => 'Resolvido.',
        'resolution_effective_seconds' => $effective,
    ])->save();
    $ticket->histories()->forceCreate([
        'user_id' => $author->id,
        'event_type' => TicketEvent::Resolved,
        'old_values' => null,
        'new_values' => ['resolved_at' => $at],
        'created_at' => Carbon::parse($at),
    ]);

    return $ticket->fresh();
}

function perf(User $user, string $query = ''): TestResponse
{
    return test()->actingAs($user)->get('/performance'.$query);
}

function perfReport(User $user, string $query = ''): ?array
{
    return perf($user, $query)->assertOk()->viewData('page')['props']['report'];
}

function perfRow(array $report, string $name): ?array
{
    return collect($report['technicians']['data'])->firstWhere('name', $name);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/performance')->assertRedirect('/login');
});

test('managers and administrators open the panel', function (string $role) {
    perf(User::factory()->withRole($role)->create())->assertOk()->assertInertia(fn (Assert $page) => $page->component('Performance/Index'));
})->with(['manager', 'admin']);

test('technicians and requesters cannot open the panel, even by direct request', function (string $role) {
    perfTicket(['assigned_to' => $this->ana->id]);

    $response = perf(User::factory()->withRole($role)->create())->assertForbidden();

    expect($response->getContent())->not->toContain('Ana Técnica');
})->with(['technician', 'requester']);

test('the permission alone or the global view alone are not enough', function () {
    $make = function (array $slugs) {
        $role = Role::create(['name' => 'Papel '.uniqid(), 'slug' => 'p-'.uniqid()]);
        $role->permissions()->attach(Permission::whereIn('slug', $slugs)->pluck('id'), ['created_at' => now()]);

        return User::factory()->create(['role_id' => $role->id]);
    };

    perf($make(['reports.view_performance']))->assertForbidden();
    perf($make(['tickets.view_all_departments']))->assertForbidden();
    perf($make(['reports.view_performance', 'tickets.view_all_departments']))->assertOk();
});

test('inactive users are denied', function () {
    perf(User::factory()->withRole('manager')->inactive()->create())->assertForbidden();
});

test('the menu offers the panel only to those who can open it', function () {
    $permissions = fn (User $user) => $this->actingAs($user)->get('/dashboard')->viewData('page')['props']['auth']['permissions'] ?? [];

    expect($permissions($this->manager))->toContain('reports.view_performance')
        ->and($permissions($this->ana))->not->toContain('reports.view_performance');
});

test('the migration grants the permission to existing manager and admin roles once', function () {
    $permission = Permission::where('slug', 'reports.view_performance')->first();
    foreach (Role::whereIn('slug', ['manager', 'admin'])->get() as $role) {
        $role->permissions()->detach($permission->id);
    }
    $permission->delete();

    $migration = require database_path('migrations/2026_10_24_000003_add_performance_report_permission.php');
    $migration->up();
    $migration->up();

    $id = Permission::where('slug', 'reports.view_performance')->value('id');

    expect(Permission::where('slug', 'reports.view_performance')->count())->toBe(1)
        ->and(DB::table('role_permissions')->where('permission_id', $id)->count())->toBe(2)
        ->and(User::factory()->withRole('technician')->create()->hasPermission('reports.view_performance'))->toBeFalse();
});

/* ---------- Período ---------- */

test('without a period, the last 30 days are used and the period is reported', function () {
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-20 09:00:00']);
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-08-01 09:00:00']);

    $page = perf($this->manager)->viewData('page')['props'];

    expect($page['period'])->toMatchArray(['from' => '2026-09-25', 'to' => '2026-10-24', 'is_default' => true, 'granularity' => 'day'])
        ->and($page['report']['totals']['attended'])->toBe(1);
});

test('indicators respect the selected period, boundaries included', function () {
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-01 00:00:00']);
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-10 23:59:59']);
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-09-30 23:59:59']);
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-11 00:00:00']);

    $report = perfReport($this->manager, '?opened_from=2026-10-01&opened_to=2026-10-10');

    expect($report['totals']['attended'])->toBe(2)
        ->and(perfRow($report, 'Ana Técnica')['attended'])->toBe(2);
});

test('resolved tickets are counted by their resolution date, not the opening date', function () {
    $old = perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-08-01 09:00:00']);
    perfResolve($old, $this->ana, '2026-10-15 10:00:00');

    $report = perfReport($this->manager, '?opened_from=2026-10-10&opened_to=2026-10-20');

    expect($report['totals']['attended'])->toBe(0)
        ->and($report['totals']['resolved'])->toBe(1)
        ->and(perfRow($report, 'Ana Técnica')['resolved'])->toBe(1);
});

test('invalid filters are rejected', function (string $query) {
    $this->actingAs($this->manager)->from('/performance')->get('/performance'.$query)
        ->assertRedirect('/performance')
        ->assertSessionHasErrors();
})->with([
    'data inválida' => '?opened_from=ontem',
    'fim antes do início' => '?opened_from=2026-10-10&opened_to=2026-10-01',
    'período longo demais' => '?opened_from=2024-01-01&opened_to=2026-10-01',
    'status inválido' => '?status=inventado',
    'SLA inválido' => '?sla=inventado',
    'técnico não numérico' => '?technician=abc',
]);

/* ---------- Contagens ---------- */

test('each ticket counts once per technician, whatever the history of reassignments', function () {
    $ticket = perfTicket(['assigned_to' => $this->bruno->id]);
    foreach ([$this->ana->id, $this->bruno->id, $this->ana->id, $this->bruno->id] as $to) {
        $ticket->histories()->forceCreate(['user_id' => $this->manager->id, 'event_type' => TicketEvent::Reassigned, 'new_values' => ['label' => 'x'], 'created_at' => now()]);
    }
    $ticket->histories()->forceCreate(['user_id' => $this->bruno->id, 'event_type' => TicketEvent::StatusChanged, 'new_values' => ['label' => 'x'], 'created_at' => now()]);

    $report = perfReport($this->manager);

    expect($report['totals']['attended'])->toBe(1)
        ->and(perfRow($report, 'Bruno Técnico')['attended'])->toBe(1)
        ->and(perfRow($report, 'Ana Técnica'))->toBeNull();
});

test('the resolution belongs to the author of the latest resolution event', function () {
    $ticket = perfTicket(['assigned_to' => $this->bruno->id]);
    perfResolve($ticket, $this->ana, '2026-10-21 10:00:00');
    // Reaberto e resolvido de novo por outro técnico: vale a resolução mais recente, e conta uma vez.
    $ticket->histories()->forceCreate(['user_id' => $this->bruno->id, 'event_type' => TicketEvent::Resolved, 'new_values' => ['resolved_at' => '2026-10-22 10:00:00'], 'created_at' => Carbon::parse('2026-10-22 10:00:00')]);
    $ticket->forceFill(['resolved_at' => Carbon::parse('2026-10-22 10:00:00')])->save();

    $report = perfReport($this->manager);

    expect($report['totals']['resolved'])->toBe(1)
        ->and(perfRow($report, 'Bruno Técnico')['resolved'])->toBe(1)
        ->and(perfRow($report, 'Ana Técnica'))->toBeNull();
});

test('a reopened ticket is not counted as resolved until it is resolved again', function () {
    $ticket = perfTicket(['assigned_to' => $this->ana->id]);
    perfResolve($ticket, $this->ana, '2026-10-21 10:00:00');
    $ticket->forceFill(['status' => TicketStatus::Open])->save();

    expect(perfReport($this->manager)['totals']['resolved'])->toBe(0);
});

test('unassigned tickets appear as no responsible and in the totals', function () {
    perfTicket(['assigned_to' => null, 'status' => TicketStatus::Open]);

    $report = perfReport($this->manager);

    expect($report['totals']['attended'])->toBe(1)
        ->and(perfRow($report, 'Sem responsável')['id'])->toBeNull();
});

test('the status distribution covers every status of the attended tickets', function () {
    perfTicket(['assigned_to' => $this->ana->id, 'status' => TicketStatus::Open]);
    perfTicket(['assigned_to' => $this->ana->id, 'status' => TicketStatus::InProgress]);
    perfTicket(['assigned_to' => $this->ana->id, 'status' => TicketStatus::InProgress]);

    $statuses = collect(perfReport($this->manager)['statuses'])->pluck('total', 'value');

    expect($statuses)->toHaveCount(8)
        ->and($statuses['open'])->toBe(1)
        ->and($statuses['in_progress'])->toBe(2)
        ->and($statuses['closed'])->toBe(0);
});

/* ---------- SLA ---------- */

test('SLA compliance uses recorded violations and current deadlines', function () {
    // No prazo, respondido; vencido por prazo (aberto em 20/10); e violado na primeira resposta (já registrada).
    $ok = perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-24 10:00:00', 'first_response_at' => '2026-10-24 10:10:00']);
    $overdue = perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-20 09:00:00']);
    $breached = perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-24 09:00:00', 'first_response_breached_at' => '2026-10-24 09:40:00', 'first_response_at' => '2026-10-24 09:40:00']);

    $row = perfRow(perfReport($this->manager), 'Ana Técnica');

    expect($row['attended'])->toBe(3)
        ->and($row['tracked'])->toBe(3)
        ->and($row['within_sla'])->toBe(1)
        ->and($row['violated_tickets'])->toBe(2)
        // O vencido por prazo tem primeira resposta e resolução vencidas; o outro, só a primeira resposta.
        ->and($row['violations'])->toBe(3)
        ->and($row['compliance_rate'])->toBe(33.3)
        ->and($ok->exists && $overdue->exists && $breached->exists)->toBeTrue();
});

test('a past violation stays counted even when the current target is back on track', function () {
    $ticket = perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-24 08:00:00']);
    // Meta mais longa depois da violação: o prazo vigente está no futuro, mas a violação foi registrada.
    $ticket->forceFill(['resolution_due_at' => now()->addDay(), 'resolution_breached_at' => '2026-10-24 11:00:00'])->save();

    $row = perfRow(perfReport($this->manager), 'Ana Técnica');

    expect($row['violated_tickets'])->toBe(1)
        ->and($row['resolution_violations'])->toBe(1)
        ->and($row['compliance_rate'])->toBe(0.0);
});

test('a paused ticket is not considered violated without a recorded violation', function () {
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-20 09:00:00', 'status' => TicketStatus::Pending, 'first_response_at' => '2026-10-20 09:10:00']);

    $row = perfRow(perfReport($this->manager), 'Ana Técnica');

    expect($row['violated_tickets'])->toBe(0)->and($row['within_sla'])->toBe(1);
});

test('tickets without an applied policy stay out of the compliance percentage', function () {
    perfTicket(['assigned_to' => $this->ana->id, 'sla_policy_id' => null, 'sla_first_response_minutes' => null, 'sla_resolution_minutes' => null, 'first_response_due_at' => null, 'resolution_due_at' => null]);

    $row = perfRow(perfReport($this->manager), 'Ana Técnica');

    expect($row['attended'])->toBe(1)
        ->and($row['tracked'])->toBe(0)
        ->and($row['compliance_rate'])->toBeNull()
        ->and($row['violation_rate'])->toBeNull();
});

test('a reopened ticket keeps the violation recorded before the reopening', function () {
    $ticket = perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-22 08:00:00', 'resolution_breached_at' => '2026-10-22 16:00:00']);
    perfResolve($ticket, $this->ana, '2026-10-22 17:00:00');
    $ticket->forceFill(['status' => TicketStatus::Open, 'resolution_due_at' => now()->addDays(3)])->save();

    expect(perfRow(perfReport($this->manager), 'Ana Técnica')['violated_tickets'])->toBe(1);
});

/* ---------- Médias ---------- */

test('averages use only valid data and are null otherwise', function () {
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-20 09:00:00', 'first_response_at' => '2026-10-20 09:10:00']);
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-21 09:00:00', 'first_response_at' => '2026-10-21 09:30:00']);
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-22 09:00:00']);
    $a = perfResolve(perfTicket(['assigned_to' => $this->ana->id]), $this->ana, '2026-10-23 10:00:00', 3600);
    perfResolve(perfTicket(['assigned_to' => $this->ana->id]), $this->ana, '2026-10-23 11:00:00', 10800);
    perfResolve(perfTicket(['assigned_to' => $this->ana->id]), $this->ana, '2026-10-23 12:00:00', null);

    $row = perfRow(perfReport($this->manager), 'Ana Técnica');

    expect($row['first_response'])->toBe(['count' => 2, 'average_seconds' => 1200])
        ->and($row['resolution'])->toBe(['count' => 2, 'average_seconds' => 7200])
        ->and($a->exists)->toBeTrue();

    $empty = perfRow(perfReport($this->manager, '?technician='.$this->bruno->id) ?? ['technicians' => ['data' => []]], 'Bruno Técnico');
    expect($empty)->toBeNull();
});

test('without any data the panel says so instead of showing zeros', function () {
    $props = perf($this->manager)->assertOk()->viewData('page')['props'];

    expect($props['report'])->toBeNull();

    perf($this->manager)->assertInertia(fn (Assert $page) => $page->where('report', null));
});

test('averages are null when no ticket has the data', function () {
    perfTicket(['assigned_to' => $this->ana->id]);

    $totals = perfReport($this->manager)['totals'];

    expect($totals['first_response'])->toBe(['count' => 0, 'average_seconds' => null])
        ->and($totals['resolution'])->toBe(['count' => 0, 'average_seconds' => null]);
});

/* ---------- Filtros ---------- */

test('filters narrow the tickets by technician, department, category, status and SLA', function () {
    $department = Department::factory()->create();
    $category = TicketCategory::factory()->create();

    perfTicket(['assigned_to' => $this->ana->id, 'department_id' => $department->id, 'category_id' => $category->id, 'created_at' => '2026-10-24 10:00:00']);
    perfTicket(['assigned_to' => $this->bruno->id, 'created_at' => '2026-10-20 09:00:00', 'status' => TicketStatus::Open]);

    expect(perfReport($this->manager, '?technician='.$this->ana->id)['totals']['attended'])->toBe(1)
        ->and(perfReport($this->manager, '?department='.$department->id)['totals']['attended'])->toBe(1)
        ->and(perfReport($this->manager, '?category='.$category->id)['totals']['attended'])->toBe(1)
        ->and(perfReport($this->manager, '?status=open')['totals']['attended'])->toBe(1)
        ->and(perfReport($this->manager, '?sla=overdue')['totals']['attended'])->toBe(1)
        ->and(perfReport($this->manager, '?sla=on_track')['totals']['attended'])->toBe(1);
});

test('the technician filter also narrows the resolved tickets by their resolver', function () {
    perfResolve(perfTicket(['assigned_to' => $this->ana->id]), $this->ana, '2026-10-21 10:00:00');
    perfResolve(perfTicket(['assigned_to' => $this->bruno->id]), $this->bruno, '2026-10-21 11:00:00');

    $report = perfReport($this->manager, '?technician='.$this->bruno->id);

    expect($report['totals']['resolved'])->toBe(1)->and(perfRow($report, 'Bruno Técnico')['resolved'])->toBe(1);
});

test('the filter options list only technicians with visible tickets', function () {
    perfTicket(['assigned_to' => $this->ana->id]);

    $options = perf($this->manager)->viewData('page')['props']['options'];

    expect(collect($options['technicians'])->pluck('label')->all())->toBe(['Ana Técnica']);
});

/* ---------- Evolução e paginação ---------- */

test('the evolution has an entry for every interval, even empty ones', function () {
    perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-20 09:00:00']);
    perfResolve(perfTicket(['assigned_to' => $this->ana->id, 'created_at' => '2026-10-21 09:00:00']), $this->ana, '2026-10-22 10:00:00');

    $report = perfReport($this->manager, '?opened_from=2026-10-18&opened_to=2026-10-24');
    $byDay = collect($report['evolution'])->keyBy('start');

    expect($report['evolution'])->toHaveCount(7)
        ->and($byDay['2026-10-20']['opened'])->toBe(1)
        ->and($byDay['2026-10-21']['opened'])->toBe(1)
        ->and($byDay['2026-10-22']['resolved'])->toBe(1)
        ->and($byDay['2026-10-19']['opened'])->toBe(0)
        ->and($byDay['2026-10-19']['compliance'])->toBeNull();
});

test('the granularity follows the period length', function (string $query, string $expected) {
    perfTicket(['assigned_to' => $this->ana->id]);

    expect(perf($this->manager, $query)->viewData('page')['props']['period']['granularity'])->toBe($expected);
})->with([
    ['?opened_from=2026-10-01&opened_to=2026-10-24', 'day'],
    ['?opened_from=2026-08-01&opened_to=2026-10-24', 'week'],
    ['?opened_from=2026-01-01&opened_to=2026-10-24', 'month'],
]);

test('the technician table is paginated on the server', function () {
    foreach (range(1, 16) as $i) {
        perfTicket(['assigned_to' => User::factory()->withRole('technician')->create(['name' => sprintf('Técnico %02d', $i)])->id]);
    }

    $first = perfReport($this->manager)['technicians'];
    $second = perfReport($this->manager, '?page=2')['technicians'];

    expect($first['total'])->toBe(16)
        ->and($first['data'])->toHaveCount(15)
        ->and($second['data'])->toHaveCount(1)
        ->and($second['current_page'])->toBe(2);
});

test('viewing the panel changes no record', function () {
    $ticket = perfTicket(['assigned_to' => $this->ana->id]);
    $before = $ticket->fresh()->toArray();
    $histories = DB::table('ticket_histories')->count();

    perf($this->manager)->assertOk();

    expect($ticket->fresh()->toArray())->toBe($before)->and(DB::table('ticket_histories')->count())->toBe($histories);
});
