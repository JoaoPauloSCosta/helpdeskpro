<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** Identificadores retornados na página atual da listagem. */
function listedNumbers(TestResponse $response): array
{
    return collect($response->viewData('page')['props']['tickets']['data'])->pluck('number')->all();
}

function listingProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/tickets')->assertRedirect('/login');
});

test('authenticated users receive the listing page', function () {
    $user = User::factory()->withRole('requester')->create();

    $this->actingAs($user)
        ->get('/tickets')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tickets/Index')
            ->has('tickets.data')
            ->where('filters', [])
            ->where('sort', ['key' => 'created_at', 'direction' => 'desc'])
            ->has('options.statuses', 8)
            ->where('hasAnyTicket', false));
});

test('inactive users get a neutral access denied page', function () {
    $user = User::factory()->withRole('manager')->inactive()->create();
    Ticket::factory()->create();

    $this->actingAs($user)
        ->get('/tickets')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->missing('tickets'));
});

/* ---------- Escopo de acesso ---------- */

test('requesters see only their own tickets', function () {
    $department = Department::factory()->create();
    $user = User::factory()->withRole('requester')->create(['department_id' => $department->id]);
    $colleague = User::factory()->withRole('requester')->create(['department_id' => $department->id]);

    $own = Ticket::factory()->create(['requester_id' => $user->id, 'department_id' => $department->id]);
    Ticket::factory()->create(['requester_id' => $colleague->id, 'department_id' => $department->id]);
    Ticket::factory()->create();

    $response = $this->actingAs($user)->get('/tickets')->assertOk();

    expect(listedNumbers($response))->toBe([$own->ticket_number])
        ->and(listingProps($response)['tickets']['total'])->toBe(1);
});

test('users without a role only see tickets they requested', function () {
    $user = User::factory()->create();
    $own = Ticket::factory()->create(['requester_id' => $user->id]);
    Ticket::factory()->create(['status' => TicketStatus::Open]);

    expect(listedNumbers($this->actingAs($user)->get('/tickets')))->toBe([$own->ticket_number]);
});

test('technicians see assigned tickets and open unassigned ones', function () {
    $tech = User::factory()->withRole('technician')->create();
    $other = User::factory()->withRole('technician')->create();

    $assigned = Ticket::factory()->create(['assigned_to' => $tech->id, 'status' => TicketStatus::InProgress]);
    $available = Ticket::factory()->create(['status' => TicketStatus::Open, 'assigned_to' => null]);
    Ticket::factory()->create(['status' => TicketStatus::InProgress, 'assigned_to' => $other->id]);
    Ticket::factory()->create(['status' => TicketStatus::Open, 'assigned_to' => $other->id]);
    Ticket::factory()->create(['status' => TicketStatus::Pending, 'assigned_to' => null]);

    expect(listedNumbers($this->actingAs($tech)->get('/tickets')))
        ->toEqualCanonicalizing([$assigned->ticket_number, $available->ticket_number]);
});

test('managers and administrators see tickets from all departments', function (string $role) {
    $user = User::factory()->withRole($role)->create();
    Ticket::factory()->count(3)->create();

    expect(listingProps($this->actingAs($user)->get('/tickets'))['tickets']['total'])->toBe(3);
})->with(['manager', 'admin']);

test('filters and search never reveal tickets outside the scope', function () {
    $user = User::factory()->withRole('requester')->create();
    $hidden = Ticket::factory()->create(['title' => 'Servidor fora do ar']);

    $byNumber = $this->actingAs($user)->get('/tickets?q='.$hidden->ticket_number);
    $byFilter = $this->actingAs($user)->get('/tickets?title='.urlencode($hidden->title).'&department='.$hidden->department_id);

    expect(listedNumbers($byNumber))->toBe([])
        ->and(listingProps($byNumber)['tickets']['total'])->toBe(0)
        ->and(listedNumbers($byFilter))->toBe([])
        ->and(listingProps($byFilter)['hasAnyTicket'])->toBeFalse();
});

test('requester and assignee filter options come only from visible tickets', function () {
    $user = User::factory()->withRole('requester')->create();
    $tech = User::factory()->create(['name' => 'Técnico Visível']);
    $stranger = User::factory()->create(['name' => 'Pessoa Oculta']);

    Ticket::factory()->create(['requester_id' => $user->id, 'assigned_to' => $tech->id]);
    Ticket::factory()->create(['requester_id' => $stranger->id, 'assigned_to' => $stranger->id]);

    $options = listingProps($this->actingAs($user)->get('/tickets'))['options'];

    // O solicitante não recebe a lista de solicitantes (RF-24); os responsáveis vêm só dos chamados visíveis.
    expect($options)->not->toHaveKey('requesters')
        ->and(collect($options['assignees'])->pluck('label')->all())->toBe(['Técnico Visível']);
});

test('requester filter options for support users come only from visible tickets', function () {
    $tech = User::factory()->withRole('technician')->create();
    $visible = User::factory()->create(['name' => 'Solicitante Visível']);
    $stranger = User::factory()->create(['name' => 'Pessoa Oculta']);

    Ticket::factory()->create(['requester_id' => $visible->id, 'assigned_to' => $tech->id, 'status' => TicketStatus::InProgress]);
    Ticket::factory()->create(['requester_id' => $stranger->id, 'assigned_to' => $stranger->id, 'status' => TicketStatus::InProgress]);

    $options = listingProps($this->actingAs($tech)->get('/tickets'))['options'];

    expect(collect($options['requesters'])->pluck('label')->all())->toBe(['Solicitante Visível']);
});

/* ---------- Dados expostos ---------- */

test('rows expose only the listing fields', function () {
    $manager = User::factory()->withRole('manager')->create();
    Ticket::factory()->create([
        'description' => 'Conteúdo interno',
        'resolution_summary' => 'Resumo',
    ]);

    $this->actingAs($manager)
        ->get('/tickets')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tickets.data', 1)
            ->has('tickets.data.0', fn (Assert $row) => $row
                ->hasAll([
                    'number', 'title', 'requester', 'department', 'category', 'priority',
                    'status', 'assignee', 'created_at', 'updated_at', 'sla',
                    // Flag de exibição da autoatribuição (CHA-06), não um dado do chamado.
                    'can_self_assign',
                ])));
});

test('rows use labels, the public identifier and neutral values for missing data', function () {
    $manager = User::factory()->withRole('manager')->create();
    $ticket = Ticket::factory()->create([
        'priority' => TicketPriority::Critical,
        'status' => TicketStatus::PendingThirdParty,
        'assigned_to' => null,
    ]);

    $row = listingProps($this->actingAs($manager)->get('/tickets'))['tickets']['data'][0];

    expect($row['number'])->toBe($ticket->ticket_number)
        ->and($row['number'])->toMatch('/^HD-[A-HJ-NP-Z2-9]{8}$/')
        ->and($row['priority'])->toBe('Crítica')
        ->and($row['status'])->toBe('Pendente Terceiros')
        ->and($row['assignee'])->toBeNull()
        ->and($row['sla'])->toBe('paused');
});

test('the opening action follows the create policy (CHA-02)', function () {
    $this->actingAs(User::factory()->withRole('requester')->create())
        ->get('/tickets')
        ->assertInertia(fn (Assert $page) => $page->where('can.create', true));
});

/* ---------- Pesquisa e filtros ---------- */

test('quick search matches the identifier or the title', function () {
    $manager = User::factory()->withRole('manager')->create();
    $printer = Ticket::factory()->create(['ticket_number' => 'HD-AAAA2222', 'title' => 'Impressora sem toner']);
    $vpn = Ticket::factory()->create(['ticket_number' => 'HD-BBBB3333', 'title' => 'VPN desconectando']);

    $this->actingAs($manager);

    expect(listedNumbers($this->get('/tickets?q=impressora')))->toBe([$printer->ticket_number])
        ->and(listedNumbers($this->get('/tickets?q=hd-bbbb')))->toBe([$vpn->ticket_number])
        ->and(listedNumbers($this->get('/tickets?q=nada')))->toBe([]);
});

test('LIKE wildcards in the search are treated as literal text', function () {
    $manager = User::factory()->withRole('manager')->create();
    Ticket::factory()->create(['title' => 'Erro geral']);

    expect(listedNumbers($this->actingAs($manager)->get('/tickets?q=%25')))->toBe([]);
});

test('attribute filters are applied on the server', function () {
    $manager = User::factory()->withRole('manager')->create();
    $requester = User::factory()->create();
    $tech = User::factory()->create();
    $department = Department::factory()->create();
    $category = TicketCategory::factory()->create();

    $match = Ticket::factory()->create([
        'ticket_number' => 'HD-MATCH234',
        'title' => 'Teclado quebrado',
        'requester_id' => $requester->id,
        'department_id' => $department->id,
        'category_id' => $category->id,
        'priority' => TicketPriority::High,
        'status' => TicketStatus::InProgress,
        'assigned_to' => $tech->id,
    ]);
    Ticket::factory()->count(2)->create();

    $this->actingAs($manager);

    foreach ([
        'number=match',
        'title=teclado',
        "requester={$requester->id}",
        "department={$department->id}",
        "category={$category->id}",
        'priority=high',
        'status=in_progress',
        "assignee={$tech->id}",
    ] as $query) {
        expect(listedNumbers($this->get("/tickets?$query")))->toBe([$match->ticket_number], $query);
    }
});

test('the unassigned filter returns tickets without a responsible', function () {
    $manager = User::factory()->withRole('manager')->create();
    $unassigned = Ticket::factory()->create(['assigned_to' => null]);
    Ticket::factory()->create(['assigned_to' => User::factory()]);

    expect(listedNumbers($this->actingAs($manager)->get('/tickets?assignee=none')))
        ->toBe([$unassigned->ticket_number]);
});

test('the opening period filter includes whole days', function () {
    $manager = User::factory()->withRole('manager')->create();
    $before = Ticket::factory()->create(['created_at' => '2026-10-09 23:59:59']);
    $first = Ticket::factory()->create(['created_at' => '2026-10-10 00:00:00']);
    $last = Ticket::factory()->create(['created_at' => '2026-10-12 23:59:59']);
    $after = Ticket::factory()->create(['created_at' => '2026-10-13 00:00:00']);

    $this->actingAs($manager);

    expect(listedNumbers($this->get('/tickets?opened_from=2026-10-10&opened_to=2026-10-12')))
        ->toEqualCanonicalizing([$first->ticket_number, $last->ticket_number])
        ->and(listedNumbers($this->get('/tickets?opened_from=2026-10-13')))->toBe([$after->ticket_number])
        ->and(listedNumbers($this->get('/tickets?opened_to=2026-10-09')))->toBe([$before->ticket_number]);
});

test('SLA situation is computed by the backend and can be filtered', function () {
    $this->travelTo('2026-10-21 12:00:00');
    $manager = User::factory()->withRole('manager')->create();

    $tickets = [
        'on_track' => Ticket::factory()->create(['status' => TicketStatus::InProgress, 'resolution_due_at' => '2026-10-21 15:00:00']),
        'overdue' => Ticket::factory()->create(['status' => TicketStatus::Open, 'resolution_due_at' => '2026-10-21 11:00:00']),
        'paused' => Ticket::factory()->create(['status' => TicketStatus::AwaitingRequester, 'resolution_due_at' => '2026-10-21 11:00:00']),
        'met' => Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution_due_at' => '2026-10-21 11:00:00',
            'resolved_at' => '2026-10-21 10:00:00',
        ]),
        'none' => Ticket::factory()->create(['status' => TicketStatus::Open, 'resolution_due_at' => null]),
    ];
    $lateResolution = Ticket::factory()->create([
        'status' => TicketStatus::Closed,
        'resolution_due_at' => '2026-10-20 11:00:00',
        'resolved_at' => '2026-10-20 18:00:00',
    ]);

    $this->actingAs($manager);

    $rows = collect(listingProps($this->get('/tickets'))['tickets']['data'])->pluck('sla', 'number');
    foreach ($tickets as $state => $ticket) {
        expect($rows[$ticket->ticket_number])->toBe($state);
    }
    expect($rows[$lateResolution->ticket_number])->toBe('overdue');

    foreach ($tickets as $state => $ticket) {
        $expected = $state === 'overdue'
            ? [$ticket->ticket_number, $lateResolution->ticket_number]
            : [$ticket->ticket_number];

        expect(listedNumbers($this->get("/tickets?sla=$state")))->toEqualCanonicalizing($expected, $state);
    }
});

test('applied filters are returned without empty values', function () {
    $manager = User::factory()->withRole('manager')->create();

    $this->actingAs($manager)
        ->get('/tickets?status=open&title=&q=%20%20&priority=low')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['priority' => 'low', 'status' => 'open']));
});

test('invalid filters are rejected', function (string $query, string $field) {
    $manager = User::factory()->withRole('manager')->create();

    $this->actingAs($manager)
        ->get("/tickets?$query")
        ->assertRedirect(route('tickets.index'))
        ->assertSessionHasErrors($field);
})->with([
    'unknown status' => ['status=deleted', 'status'],
    'unknown priority' => ['priority=urgent', 'priority'],
    'unknown SLA' => ['sla=almost', 'sla'],
    'malformed date' => ['opened_from=21/10/2026', 'opened_from'],
    'inverted period' => ['opened_from=2026-10-20&opened_to=2026-10-10', 'opened_to'],
    'non numeric department' => ['department=abc', 'department'],
    'assignee injection' => ['assignee=1%20OR%201=1', 'assignee'],
    'arbitrary sort column' => ['sort=password', 'sort'],
    'arbitrary direction' => ['direction=sideways', 'direction'],
]);

/* ---------- Ordenação e paginação ---------- */

test('sorting follows the domain order for priority and status', function () {
    $manager = User::factory()->withRole('manager')->create();
    $critical = Ticket::factory()->create(['priority' => TicketPriority::Critical, 'status' => TicketStatus::Closed]);
    $low = Ticket::factory()->create(['priority' => TicketPriority::Low, 'status' => TicketStatus::InProgress]);
    $high = Ticket::factory()->create(['priority' => TicketPriority::High, 'status' => TicketStatus::Open]);

    $this->actingAs($manager);

    expect(listedNumbers($this->get('/tickets?sort=priority&direction=desc')))
        ->toBe([$critical->ticket_number, $high->ticket_number, $low->ticket_number])
        ->and(listedNumbers($this->get('/tickets?sort=status&direction=asc')))
        ->toBe([$high->ticket_number, $low->ticket_number, $critical->ticket_number]);
});

test('sorting by identifier, title, dates and responsible works', function () {
    $manager = User::factory()->withRole('manager')->create();
    $ana = User::factory()->create(['name' => 'Ana']);
    $bruno = User::factory()->create(['name' => 'Bruno']);

    $a = Ticket::factory()->create(['ticket_number' => 'HD-AAAAAAAA', 'title' => 'Zeta', 'assigned_to' => $bruno->id, 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-05 10:00:00']);
    $b = Ticket::factory()->create(['ticket_number' => 'HD-BBBBBBBB', 'title' => 'Alfa', 'assigned_to' => $ana->id, 'created_at' => '2026-10-02 10:00:00', 'updated_at' => '2026-10-03 10:00:00']);

    $this->actingAs($manager);

    expect(listedNumbers($this->get('/tickets')))->toBe([$b->ticket_number, $a->ticket_number])
        ->and(listedNumbers($this->get('/tickets?sort=number&direction=asc')))->toBe([$a->ticket_number, $b->ticket_number])
        ->and(listedNumbers($this->get('/tickets?sort=title&direction=asc')))->toBe([$b->ticket_number, $a->ticket_number])
        ->and(listedNumbers($this->get('/tickets?sort=updated_at&direction=desc')))->toBe([$a->ticket_number, $b->ticket_number])
        ->and(listedNumbers($this->get('/tickets?sort=assignee&direction=asc')))->toBe([$b->ticket_number, $a->ticket_number]);
});

test('pagination happens on the server and keeps filters and sorting', function () {
    $manager = User::factory()->withRole('manager')->create();
    Ticket::factory()->count(20)->create(['status' => TicketStatus::Open]);
    Ticket::factory()->count(3)->create(['status' => TicketStatus::Closed]);

    $this->actingAs($manager);

    $first = listingProps($this->get('/tickets?status=open&sort=number&direction=asc'))['tickets'];
    $second = listingProps($this->get('/tickets?status=open&sort=number&direction=asc&page=2'))['tickets'];

    expect($first['total'])->toBe(20)
        ->and($first['data'])->toHaveCount(15)
        ->and($second['data'])->toHaveCount(5)
        ->and($first['next_page_url'])->toContain('status=open')
        ->and($first['next_page_url'])->toContain('sort=number')
        ->and(array_intersect(
            collect($first['data'])->pluck('number')->all(),
            collect($second['data'])->pluck('number')->all(),
        ))->toBe([]);
});
