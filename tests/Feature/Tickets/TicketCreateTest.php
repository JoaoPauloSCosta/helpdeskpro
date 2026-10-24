<?php

use App\Actions\Tickets\OpenTicket;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\TicketNumberGenerator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->department = Department::factory()->create(['name' => 'Financeiro']);
    $this->category = TicketCategory::factory()->create(['name' => 'Hardware']);
    $this->requester = User::factory()->withRole('requester')->create(['department_id' => $this->department->id]);
});

/** Dados válidos de abertura, com um token de envio novo a cada chamada. */
function ticketPayload(array $overrides = []): array
{
    return [
        'title' => 'Computador não conecta à rede',
        'description' => 'Desde hoje cedo o cabo de rede não é reconhecido.',
        'category_id' => test()->category->id,
        'submission_token' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/** Gerador com uma sequência fixa de identificadores, para simular colisões. */
function fakeTicketNumbers(array $numbers): void
{
    app()->instance(TicketNumberGenerator::class, new class($numbers) extends TicketNumberGenerator
    {
        public function __construct(private array $numbers) {}

        public function generate(): string
        {
            return array_shift($this->numbers) ?? throw new LogicException('Sequência esgotada.');
        }
    });
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/tickets/create')->assertRedirect('/login');
    $this->post('/tickets', ticketPayload())->assertRedirect('/login');

    expect(Ticket::count())->toBe(0);
});

test('users without a role or inactive users cannot open tickets', function (Closure $makeUser) {
    $user = $makeUser();

    $this->actingAs($user)->get('/tickets/create')->assertForbidden();
    $this->actingAs($user)->post('/tickets', ticketPayload())->assertForbidden();

    expect(Ticket::count())->toBe(0);
})->with([
    'sem papel' => fn () => User::factory()->create(['department_id' => test()->department->id]),
    'inativo' => fn () => User::factory()->withRole('requester')->inactive()->create(['department_id' => test()->department->id]),
]);

test('the listing offers the create action to authorized users', function () {
    $this->actingAs($this->requester)
        ->get('/tickets')
        ->assertInertia(fn (Assert $page) => $page->where('can.create', true));

    $this->actingAs(User::factory()->create())
        ->get('/tickets')
        ->assertInertia(fn (Assert $page) => $page->where('can.create', false));
});

/* ---------- Página ---------- */

test('the create page shows real requester data and only active categories', function () {
    TicketCategory::factory()->create(['name' => 'Antiga', 'is_active' => false]);

    $this->actingAs($this->requester)
        ->get('/tickets/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tickets/Create')
            ->where('categories', [['value' => (string) $this->category->id, 'label' => 'Hardware']])
            // O solicitante não escolhe a prioridade (RF-05): o formulário não recebe a lista.
            ->missing('priorities')
            ->where('requester', [
                'name' => $this->requester->name,
                'email' => $this->requester->email,
                'department' => 'Financeiro',
                'departmentActive' => true,
            ])
            ->where('limits', ['title' => Ticket::TITLE_MAX_LENGTH, 'description' => Ticket::DESCRIPTION_MAX_LENGTH])
            ->where('submissionToken', fn (string $token) => Str::isUuid($token)));
});

test('the create page flags a requester without department', function () {
    $user = User::factory()->withRole('requester')->create();

    $this->actingAs($user)
        ->get('/tickets/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requester.department', null)
            ->where('requester.departmentActive', false));
});

/* ---------- Abertura ---------- */

test('an authorized user opens a ticket with backend-defined fields', function () {
    $response = $this->actingAs($this->requester)
        ->post('/tickets', ticketPayload())
        ->assertSessionHasNoErrors();

    $ticket = Ticket::sole();
    $response->assertRedirect(route('tickets.show', $ticket->ticket_number));

    expect($ticket->title)->toBe('Computador não conecta à rede')
        ->and($ticket->description)->toBe('Desde hoje cedo o cabo de rede não é reconhecido.')
        ->and($ticket->category_id)->toBe($this->category->id)
        ->and($ticket->priority)->toBe(TicketPriority::Medium)
        ->and($ticket->requester_id)->toBe($this->requester->id)
        ->and($ticket->department_id)->toBe($this->department->id)
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->assigned_to)->toBeNull()
        ->and($ticket->ticket_number)->toMatch('/^HD-['.TicketNumberGenerator::ALPHABET.']{8}$/');

    // Sem política ativa para a prioridade Média (D2): abre sem prazo e sem política aplicada.
    expect($ticket->first_response_due_at)->toBeNull()
        ->and($ticket->resolution_due_at)->toBeNull()
        ->and($ticket->sla_policy_id)->toBeNull()
        ->and($ticket->sla_first_response_minutes)->toBeNull()
        ->and($ticket->sla_resolution_minutes)->toBeNull();
});

test('the active policy of the Medium priority sets the copied targets and the deadlines from creation', function () {
    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
    $policy = SlaPolicy::factory()->create(['priority' => 'medium', 'first_response_minutes' => 30, 'resolution_minutes' => 480]);
    SlaPolicy::factory()->create(['priority' => 'low', 'first_response_minutes' => 5, 'resolution_minutes' => 10]);
    SlaPolicy::factory()->inactive()->create(['priority' => 'medium', 'first_response_minutes' => 1, 'resolution_minutes' => 2]);

    $this->actingAs($this->requester)->post('/tickets', ticketPayload())->assertSessionHasNoErrors();

    $ticket = Ticket::sole();

    expect($ticket->sla_policy_id)->toBe($policy->id)
        ->and($ticket->sla_first_response_minutes)->toBe(30)
        ->and($ticket->sla_resolution_minutes)->toBe(480)
        ->and($ticket->created_at->toDateTimeString())->toBe('2026-10-21 08:00:00')
        ->and($ticket->first_response_due_at->toDateTimeString())->toBe('2026-10-21 08:30:00')
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 16:00:00')
        ->and($ticket->first_response_at)->toBeNull()
        ->and($ticket->first_response_breached_at)->toBeNull()
        ->and($ticket->resolution_breached_at)->toBeNull();
});

test('the creation event records the applied policy and deadlines', function () {
    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
    $policy = SlaPolicy::factory()->create(['priority' => 'medium', 'first_response_minutes' => 30, 'resolution_minutes' => 480]);

    $this->actingAs($this->requester)->post('/tickets', ticketPayload())->assertSessionHasNoErrors();

    $event = Ticket::sole()->histories()->where('event_type', TicketEvent::Created)->sole();

    expect($event->new_values['sla'])->toBe([
        'policy_id' => $policy->id,
        'first_response_minutes' => 30,
        'resolution_minutes' => 480,
        'first_response_due_at' => '2026-10-21T08:30:00+00:00',
        'resolution_due_at' => '2026-10-21T16:00:00+00:00',
    ]);
});

test('a Medium priority without active policy opens without deadlines and the event records it', function () {
    SlaPolicy::factory()->create(['priority' => 'low']);
    SlaPolicy::factory()->inactive()->create(['priority' => 'medium']);

    $this->actingAs($this->requester)->post('/tickets', ticketPayload())->assertSessionHasNoErrors();

    $ticket = Ticket::sole();
    $event = $ticket->histories()->where('event_type', TicketEvent::Created)->sole();

    expect($ticket->sla_policy_id)->toBeNull()
        ->and($ticket->first_response_due_at)->toBeNull()
        ->and($ticket->resolution_due_at)->toBeNull()
        ->and($event->new_values['sla'])->toBe(['policy_id' => null, 'no_policy' => true]);
});

test('changing the policy later does not change tickets already opened', function () {
    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
    $policy = SlaPolicy::factory()->create(['priority' => 'medium', 'first_response_minutes' => 30, 'resolution_minutes' => 480]);

    $this->actingAs($this->requester)->post('/tickets', ticketPayload())->assertSessionHasNoErrors();

    $policy->update(['first_response_minutes' => 5, 'resolution_minutes' => 10]);

    $ticket = Ticket::sole()->fresh();

    expect($ticket->sla_first_response_minutes)->toBe(30)
        ->and($ticket->sla_resolution_minutes)->toBe(480)
        ->and($ticket->first_response_due_at->toDateTimeString())->toBe('2026-10-21 08:30:00')
        ->and($ticket->resolution_due_at->toDateTimeString())->toBe('2026-10-21 16:00:00');
});

test('the success redirect carries the generated identifier to the details page', function () {
    $response = $this->actingAs($this->requester)->post('/tickets', ticketPayload());

    $number = Ticket::sole()->ticket_number;
    $response->assertSessionHas('ticketCreated', $number);

    $this->actingAs($this->requester)
        ->withSession(['ticketCreated' => $number])
        ->get("/tickets/{$number}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tickets/Show')
            ->where('flash.ticketCreated', $number));
});

test('manipulated fields cannot choose author, department, status or identifier', function () {
    $otherDepartment = Department::factory()->create();
    $otherUser = User::factory()->withRole('requester')->create(['department_id' => $otherDepartment->id]);
    $technician = User::factory()->withRole('technician')->create();

    $this->actingAs($this->requester)->post('/tickets', ticketPayload([
        'requester_id' => $otherUser->id,
        'department_id' => $otherDepartment->id,
        'department' => $otherDepartment->id,
        'assigned_to' => $technician->id,
        'status' => TicketStatus::Resolved->value,
        'ticket_number' => 'HD-AAAAAAAA',
        'resolution_due_at' => '2030-01-01 00:00:00',
        'resolved_at' => '2030-01-01 00:00:00',
    ]))->assertSessionHasNoErrors();

    $ticket = Ticket::sole();

    expect($ticket->requester_id)->toBe($this->requester->id)
        ->and($ticket->department_id)->toBe($this->department->id)
        ->and($ticket->assigned_to)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->ticket_number)->not->toBe('HD-AAAAAAAA')
        ->and($ticket->resolution_due_at)->toBeNull()
        ->and($ticket->resolved_at)->toBeNull();
});

test('the requester cannot choose the priority: every ticket opens as Medium', function () {
    foreach (['critical', 'high', 'low', 'nao-existe', ''] as $priority) {
        $this->actingAs($this->requester)->post('/tickets', ticketPayload(['priority' => $priority]))->assertSessionHasNoErrors();
    }

    expect(Ticket::pluck('priority')->unique()->all())->toBe([TicketPriority::Medium]);
});

test('the historical department is kept when the requester changes department', function () {
    $this->actingAs($this->requester)->post('/tickets', ticketPayload());

    $this->requester->forceFill(['department_id' => Department::factory()->create()->id])->save();

    expect(Ticket::sole()->department_id)->toBe($this->department->id);
});

test('every ticket receives a distinct identifier', function () {
    foreach (range(1, 5) as $ignored) {
        $this->actingAs($this->requester)->post('/tickets', ticketPayload())->assertSessionHasNoErrors();
    }

    expect(Ticket::pluck('ticket_number')->unique())->toHaveCount(5);
});

test('the generator only produces the public format', function () {
    $generator = new TicketNumberGenerator;

    foreach (range(1, 200) as $ignored) {
        expect($generator->generate())->toMatch('/^HD-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/');
    }
});

/* ---------- Validação ---------- */

test('invalid data is rejected without creating a ticket', function (array $overrides, string $field) {
    $this->actingAs($this->requester)
        ->from('/tickets/create')
        ->post('/tickets', ticketPayload($overrides))
        ->assertRedirect('/tickets/create')
        ->assertSessionHasErrors($field);

    expect(Ticket::count())->toBe(0);
})->with([
    'título vazio' => [['title' => '   '], 'title'],
    'título longo' => [['title' => str_repeat('a', Ticket::TITLE_MAX_LENGTH + 1)], 'title'],
    'descrição vazia' => [['description' => ''], 'description'],
    'descrição longa' => [['description' => str_repeat('a', Ticket::DESCRIPTION_MAX_LENGTH + 1)], 'description'],
    'sem categoria' => [['category_id' => ''], 'category_id'],
    'categoria inexistente' => [['category_id' => 999999], 'category_id'],
    'sem token de envio' => [['submission_token' => ''], 'submission_token'],
]);

test('inactive categories are rejected', function () {
    $inactive = TicketCategory::factory()->create(['is_active' => false]);

    $this->actingAs($this->requester)
        ->post('/tickets', ticketPayload(['category_id' => $inactive->id]))
        ->assertSessionHasErrors('category_id');

    expect(Ticket::count())->toBe(0);
});

test('the limits accept values exactly at the maximum', function () {
    $this->actingAs($this->requester)->post('/tickets', ticketPayload([
        'title' => str_repeat('á', Ticket::TITLE_MAX_LENGTH),
        'description' => str_repeat('ç', Ticket::DESCRIPTION_MAX_LENGTH),
    ]))->assertSessionHasNoErrors();

    expect(mb_strlen(Ticket::sole()->title))->toBe(Ticket::TITLE_MAX_LENGTH);
});

test('requesters without an active department cannot open tickets', function (Closure $makeUser) {
    $this->actingAs($makeUser())
        ->post('/tickets', ticketPayload())
        ->assertSessionHasErrors('department');

    expect(Ticket::count())->toBe(0);
})->with([
    'sem departamento' => fn () => User::factory()->withRole('requester')->create(),
    'departamento inativo' => fn () => User::factory()->withRole('requester')->create([
        'department_id' => Department::factory()->create(['is_active' => false])->id,
    ]),
]);

/* ---------- Identificador: colisões ---------- */

test('a colliding identifier is retried without overwriting the existing ticket', function () {
    $existing = Ticket::factory()->create(['ticket_number' => 'HD-AAAAAAAA', 'title' => 'Original']);
    fakeTicketNumbers(['HD-AAAAAAAA', 'HD-BBBBBBBB']);

    $this->actingAs($this->requester)->post('/tickets', ticketPayload())->assertSessionHasNoErrors();

    expect($existing->fresh()->title)->toBe('Original')
        ->and(Ticket::where('requester_id', $this->requester->id)->sole()->ticket_number)->toBe('HD-BBBBBBBB');
});

test('opening fails in a controlled way when no unique identifier is found', function () {
    Ticket::factory()->create(['ticket_number' => 'HD-AAAAAAAA']);
    fakeTicketNumbers(array_fill(0, OpenTicket::MAX_ATTEMPTS, 'HD-AAAAAAAA'));

    $this->actingAs($this->requester)
        ->post('/tickets', ticketPayload())
        ->assertSessionHasErrors('form');

    expect(Ticket::count())->toBe(1);
});

/* ---------- Envio repetido ---------- */

test('repeating the same submission does not duplicate the ticket', function () {
    $payload = ticketPayload();

    $this->actingAs($this->requester)->post('/tickets', $payload)->assertSessionHasNoErrors();
    $number = Ticket::sole()->ticket_number;

    $this->actingAs($this->requester)
        ->post('/tickets', $payload)
        ->assertRedirect(route('tickets.show', $number))
        ->assertSessionHas('ticketCreated', $number);

    expect(Ticket::count())->toBe(1);
});

test('a failed submission can be retried with the same token', function () {
    Ticket::factory()->create(['ticket_number' => 'HD-AAAAAAAA']);
    $payload = ticketPayload();

    fakeTicketNumbers(array_fill(0, OpenTicket::MAX_ATTEMPTS, 'HD-AAAAAAAA'));
    $this->actingAs($this->requester)->post('/tickets', $payload)->assertSessionHasErrors('form');

    fakeTicketNumbers(['HD-CCCCCCCC']);
    $this->actingAs($this->requester)->post('/tickets', $payload)->assertSessionHasNoErrors();

    expect(Ticket::where('ticket_number', 'HD-CCCCCCCC')->exists())->toBeTrue();
});
