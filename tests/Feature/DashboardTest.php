<?php

use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\TicketSlaPause;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
});

function dashboardProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

function dashboardMetrics(User $user, string $query = ''): ?array
{
    return dashboardProps(test()->actingAs($user)->get('/dashboard'.$query)->assertOk())['metrics'];
}

/** Totais de uma distribuição ou ranking, indexados pelo valor do filtro da listagem. */
function totalsByValue(array $items): array
{
    return collect($items)->mapWithKeys(fn (array $item) => [$item['value'] => $item['total']])->all();
}

function reopenEvent(Ticket $ticket): void
{
    TicketHistory::forceCreate([
        'ticket_id' => $ticket->id,
        'user_id' => $ticket->requester_id,
        'event_type' => TicketEvent::Reopened,
        'old_values' => ['status' => TicketStatus::Resolved->value],
        'new_values' => ['status' => TicketStatus::Open->value, 'justification' => 'O problema voltou.'],
        'created_at' => now(),
    ]);
}

function slaPauseRecord(Ticket $ticket, ?int $durationSeconds): TicketSlaPause
{
    $startedAt = now()->subDay();

    return TicketSlaPause::forceCreate([
        'ticket_id' => $ticket->id,
        'pause_status' => TicketStatus::Pending,
        'reason' => 'Aguardando peça.',
        'started_at' => $startedAt,
        'ended_at' => $durationSeconds === null ? null : $startedAt->copy()->addSeconds($durationSeconds),
        'duration_seconds' => $durationSeconds,
        'created_by' => $ticket->requester_id,
    ]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('active users receive the dashboard inside the authenticated layout', function () {
    $user = User::factory()->withRole('manager')->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('filters', [])
            ->where('metrics', null)
            ->where('hasAnyTicket', false)
            ->where('can.create', true)
            ->where('scope', ['allDepartments' => true, 'includesAvailable' => false])
            ->has('generatedAt'));
});

test('inactive users get the neutral access denied page without indicators', function () {
    $user = User::factory()->withRole('manager')->inactive()->create();
    Ticket::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->missing('metrics'));
});

/* ---------- Cálculo dos indicadores ---------- */

test('indicators are calculated from the stored tickets', function () {
    $manager = User::factory()->withRole('manager')->create();
    $tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $support = Department::factory()->create(['name' => 'Suporte']);
    $finance = Department::factory()->create(['name' => 'Financeiro']);

    Ticket::factory()->count(2)->create(['status' => TicketStatus::Open, 'priority' => TicketPriority::Low, 'department_id' => $support->id]);
    Ticket::factory()->create(['status' => TicketStatus::InProgress, 'priority' => TicketPriority::High, 'department_id' => $finance->id, 'assigned_to' => $tech->id]);
    Ticket::factory()->create(['status' => TicketStatus::PendingThirdParty, 'priority' => TicketPriority::Critical, 'department_id' => $support->id, 'assigned_to' => $tech->id]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved, 'priority' => TicketPriority::Low, 'department_id' => $finance->id, 'assigned_to' => $tech->id]);

    $metrics = dashboardMetrics($manager);

    expect($metrics['total'])->toBe(5)
        ->and(totalsByValue($metrics['byStatus']))->toBe([
            'open' => 2, 'in_progress' => 1, 'awaiting_requester' => 0, 'pending' => 0,
            'pending_third_party' => 1, 'pending_departments' => 0, 'resolved' => 1, 'closed' => 0,
        ])
        ->and(collect($metrics['byStatus'])->pluck('label')->all())->toBe(array_map(fn (TicketStatus $s) => $s->label(), TicketStatus::cases()))
        ->and(totalsByValue($metrics['byPriority']))->toBe(['low' => 3, 'medium' => 0, 'high' => 1, 'critical' => 1])
        ->and($metrics['byDepartment']['items'])->toBe([
            ['value' => (string) $support->id, 'label' => 'Suporte', 'total' => 3],
            ['value' => (string) $finance->id, 'label' => 'Financeiro', 'total' => 2],
        ])
        ->and($metrics['byDepartment']['others'])->toBeNull()
        ->and($metrics['byAssignee']['items'])->toBe([
            ['value' => (string) $tech->id, 'label' => 'Carlos Lima', 'total' => 3],
            ['value' => 'none', 'label' => 'Sem responsável', 'total' => 2],
        ])
        ->and($metrics['reopened'])->toBe(0)
        ->and($metrics['pending'])->toBe(['closed' => 0, 'closed_seconds' => 0, 'active' => 0]);
});

test('rankings keep the largest items and summarize the rest', function () {
    $manager = User::factory()->withRole('manager')->create();
    $departments = Department::factory()->count(10)->create();

    $departments->each(fn (Department $department, int $i) => Ticket::factory()
        ->count($i + 1)
        ->create(['department_id' => $department->id]));

    $ranking = dashboardMetrics($manager)['byDepartment'];

    expect($ranking['items'])->toHaveCount(8)
        ->and(collect($ranking['items'])->pluck('total')->all())->toBe([10, 9, 8, 7, 6, 5, 4, 3])
        ->and($ranking['others'])->toBe(['count' => 2, 'total' => 3]);
});

test('overdue SLA uses the recorded deadlines and reports when none exist', function () {
    $manager = User::factory()->withRole('manager')->create();
    Ticket::factory()->count(2)->create();

    expect(dashboardMetrics($manager)['sla'])->toBe(['overdue' => 0, 'tracked' => 0]);

    Ticket::factory()->create(['status' => TicketStatus::InProgress, 'resolution_due_at' => now()->subHour()]);
    Ticket::factory()->create(['status' => TicketStatus::InProgress, 'resolution_due_at' => now()->addHour()]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved, 'resolution_due_at' => now()->subHours(3), 'resolved_at' => now()->subHours(2)]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved, 'resolution_due_at' => now()->subHours(2), 'resolved_at' => now()->subHours(3)]);
    // Pausado: o prazo não conta enquanto o SLA está parado.
    Ticket::factory()->create(['status' => TicketStatus::Pending, 'resolution_due_at' => now()->subHour()]);

    expect(dashboardMetrics($manager)['sla'])->toBe(['overdue' => 2, 'tracked' => 5]);
});

test('reopened tickets are counted once regardless of how many reopenings they had', function () {
    $manager = User::factory()->withRole('manager')->create();
    $twice = Ticket::factory()->create();
    $once = Ticket::factory()->create();
    Ticket::factory()->create();

    reopenEvent($twice);
    reopenEvent($twice);
    reopenEvent($once);

    expect(dashboardMetrics($manager)['reopened'])->toBe(2);
});

test('pending time sums closed pauses and only counts the active ones', function () {
    $manager = User::factory()->withRole('manager')->create();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $other = Ticket::factory()->create();

    slaPauseRecord($ticket, 3600);
    slaPauseRecord($other, 1800);
    slaPauseRecord($ticket, null);

    expect(dashboardMetrics($manager)['pending'])->toBe(['closed' => 2, 'closed_seconds' => 5400, 'active' => 1]);
});

/* ---------- Escopo de acesso ---------- */

test('the requester has no overview: the dashboard sends them to their tickets', function () {
    $user = User::factory()->withRole('requester')->create();
    Ticket::factory()->create(['requester_id' => $user->id]);

    $this->actingAs($user)->get('/dashboard')->assertRedirect('/tickets');
    $this->actingAs($user)->get('/dashboard?opened_from=2026-10-10')->assertRedirect('/tickets');
});

test('technicians count assigned tickets and the self-assignment pool, as in the listing', function () {
    $tech = User::factory()->withRole('technician')->create();
    $other = User::factory()->withRole('technician')->create();

    Ticket::factory()->create(['assigned_to' => $tech->id, 'status' => TicketStatus::InProgress]);
    Ticket::factory()->create(['status' => TicketStatus::Open, 'assigned_to' => null]);
    Ticket::factory()->create(['status' => TicketStatus::InProgress, 'assigned_to' => $other->id]);
    Ticket::factory()->create(['status' => TicketStatus::Pending, 'assigned_to' => null]);

    $response = $this->actingAs($tech)->get('/dashboard')->assertOk();
    $metrics = dashboardProps($response)['metrics'];
    $listed = $this->actingAs($tech)->get('/tickets')->viewData('page')['props']['tickets']['total'];

    expect($metrics['total'])->toBe(2)
        ->and($metrics['total'])->toBe($listed)
        ->and(totalsByValue($metrics['byStatus'])['open'])->toBe(1)
        ->and(totalsByValue($metrics['byStatus'])['in_progress'])->toBe(1)
        ->and(dashboardProps($response)['scope'])->toBe(['allDepartments' => false, 'includesAvailable' => true]);
});

test('managers and administrators see indicators for all departments', function (string $role) {
    $user = User::factory()->withRole($role)->create();
    Ticket::factory()->count(3)->create();

    $response = $this->actingAs($user)->get('/dashboard')->assertOk();

    expect(dashboardProps($response)['metrics']['total'])->toBe(3)
        ->and(dashboardProps($response)['scope']['allDepartments'])->toBeTrue();
})->with(['manager', 'admin']);

/* ---------- Período ---------- */

test('the period selects tickets by opening date, including both whole days', function () {
    $manager = User::factory()->withRole('manager')->create();

    Ticket::factory()->create(['created_at' => '2026-10-09 23:59:59']);
    $first = Ticket::factory()->create(['created_at' => '2026-10-10 00:00:00', 'status' => TicketStatus::Resolved]);
    $last = Ticket::factory()->create(['created_at' => '2026-10-15 23:59:59']);
    Ticket::factory()->create(['created_at' => '2026-10-16 00:00:00']);
    reopenEvent($first);
    slaPauseRecord($last, 600);

    $response = $this->actingAs($manager)->get('/dashboard?opened_from=2026-10-10&opened_to=2026-10-15')->assertOk();
    $metrics = dashboardProps($response)['metrics'];

    expect(dashboardProps($response)['filters'])->toBe(['opened_from' => '2026-10-10', 'opened_to' => '2026-10-15'])
        ->and($metrics['total'])->toBe(2)
        ->and(totalsByValue($metrics['byStatus'])['resolved'])->toBe(1)
        ->and($metrics['reopened'])->toBe(1)
        ->and($metrics['pending']['closed_seconds'])->toBe(600)
        ->and(dashboardMetrics($manager, '?opened_from=2026-10-16')['total'])->toBe(1)
        ->and(dashboardMetrics($manager, '?opened_to=2026-10-09')['total'])->toBe(1);
});

test('a period without tickets is distinguished from a scope without tickets', function () {
    $manager = User::factory()->withRole('manager')->create();
    Ticket::factory()->create(['created_at' => '2026-10-01 10:00:00']);

    $this->actingAs($manager)
        ->get('/dashboard?opened_from=2026-10-20&opened_to=2026-10-21')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics', null)
            ->where('hasAnyTicket', true));
});

test('the period cannot reveal tickets outside the scope', function () {
    $user = User::factory()->withRole('technician')->create();
    Ticket::factory()->create([
        'created_at' => '2026-10-10 10:00:00',
        'assigned_to' => User::factory()->withRole('technician')->create()->id,
        'status' => TicketStatus::InProgress,
    ]);

    $this->actingAs($user)
        ->get('/dashboard?opened_from=2026-10-10&opened_to=2026-10-10')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics', null)
            ->where('hasAnyTicket', false));
});

test('invalid periods are rejected with field errors', function (string $query, string $field) {
    $manager = User::factory()->withRole('manager')->create();

    $this->actingAs($manager)
        ->get('/dashboard'.$query)
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors($field);
})->with([
    'end before start' => ['?opened_from=2026-10-15&opened_to=2026-10-10', 'opened_to'],
    'wrong format' => ['?opened_from=15/10/2026', 'opened_from'],
    'not a date' => ['?opened_to=amanha', 'opened_to'],
]);

/* ---------- Somente leitura ---------- */

test('loading the dashboard does not modify tickets or their history', function () {
    $manager = User::factory()->withRole('manager')->create();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open]);
    reopenEvent($ticket);
    slaPauseRecord($ticket, 60);

    $before = [$ticket->fresh()->toArray(), TicketHistory::count(), TicketSlaPause::count()];

    $this->travel(5)->minutes();
    $this->actingAs($manager)->get('/dashboard')->assertOk();

    expect([$ticket->fresh()->toArray(), TicketHistory::count(), TicketSlaPause::count()])->toBe($before);
});
