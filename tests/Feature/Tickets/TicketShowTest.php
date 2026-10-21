<?php

use App\Enums\CommentVisibility;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza']);
    $this->ticket = Ticket::factory()->create([
        'requester_id' => $this->requester->id,
        'title' => 'Impressora sem toner',
        'description' => "Linha 1\nLinha 2 <script>alert(1)</script>",
    ]);
});

function showUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}";
}

function detailProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get(showUrl($this->ticket))->assertRedirect('/login');
    $this->post(showUrl($this->ticket).'/comments', ['body' => 'Olá'])->assertRedirect('/login');
});

test('the requester sees the ticket with only the data the page needs', function () {
    $this->actingAs($this->requester)
        ->get(showUrl($this->ticket))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tickets/Show')
            ->where('ticket.number', $this->ticket->ticket_number)
            ->where('ticket.title', 'Impressora sem toner')
            ->where('ticket.description', "Linha 1\nLinha 2 <script>alert(1)</script>")
            ->where('ticket.requester', 'Ana Souza')
            ->where('ticket.status', 'Aberto')
            ->where('ticket.priority', 'Média')
            ->where('ticket.assignee', null)
            // Nenhuma chave primária ou dado interno do chamado na resposta.
            ->where('ticket', fn ($ticket) => collect($ticket)->keys()->sort()->values()->all() === [
                'assignee', 'category', 'created_at', 'department', 'description', 'number',
                'priority', 'requester', 'status', 'title', 'updated_at',
            ])
            ->has('sla.firstResponse')
            ->has('sla.resolution')
            ->has('comments', 0)
            // Sem `audit.view`, nenhum evento de auditoria é enviado.
            ->where('history', null)
            ->where('can', [
                'comment' => true, 'addInternalNote' => false, 'update' => false,
                'assign' => false, 'reassign' => false, 'selfAssign' => false, 'changeStatus' => false,
            ])
            ->where('assignment', null)
            ->where('statusChange', null)
            ->missing('assignableTechnicians'));
});

test('an unknown identifier returns not found', function () {
    $this->actingAs($this->requester)->get('/tickets/HD-ZZZZZZZZ')->assertNotFound();
});

test('users outside the scope get access denied without ticket data', function () {
    $stranger = User::factory()->withRole('requester')->create();

    $response = $this->actingAs($stranger)->get(showUrl($this->ticket))->assertForbidden();

    $response->assertInertia(fn (Assert $page) => $page->component('Error')->missing('ticket')->missing('comments'));
    expect($response->getContent())->not->toContain('Impressora sem toner');
});

test('inactive users cannot see their own tickets', function () {
    $this->requester->forceFill(['is_active' => false])->save();

    $this->actingAs($this->requester)->get(showUrl($this->ticket))->assertForbidden();
});

test('technicians see assigned and self-assignable tickets only', function () {
    $tech = User::factory()->withRole('technician')->create();
    $assigned = Ticket::factory()->create(['assigned_to' => $tech->id, 'status' => TicketStatus::InProgress]);
    $othersInProgress = Ticket::factory()->create([
        'assigned_to' => User::factory()->create()->id,
        'status' => TicketStatus::InProgress,
    ]);

    $this->actingAs($tech)->get(showUrl($assigned))->assertOk();
    $this->actingAs($tech)->get(showUrl($this->ticket))->assertOk(); // aberto e sem responsável
    $this->actingAs($tech)->get(showUrl($othersInProgress))->assertForbidden();
});

test('managers and administrators see tickets from any department', function (string $role) {
    $this->actingAs(User::factory()->withRole($role)->create())->get(showUrl($this->ticket))->assertOk();
})->with(['manager', 'admin']);

/* ---------- Comentários e notas internas ---------- */

test('requesters never receive internal notes', function () {
    TicketComment::factory()->for($this->ticket)->create(['body' => 'Resposta pública']);
    TicketComment::factory()->for($this->ticket)->internal()->create(['body' => 'Segredo da equipe']);

    $response = $this->actingAs($this->requester)->get(showUrl($this->ticket))->assertOk();

    expect(collect(detailProps($response)['comments'])->pluck('body')->all())->toBe(['Resposta pública'])
        ->and($response->getContent())->not->toContain('Segredo da equipe');
});

test('support users with permission see internal notes flagged as internal', function (string $role) {
    TicketComment::factory()->for($this->ticket)->internal()->create(['body' => 'Segredo da equipe']);

    $response = $this->actingAs(User::factory()->withRole($role)->create())->get(showUrl($this->ticket));

    expect(detailProps($response)['comments'])->toHaveCount(1)
        ->and(detailProps($response)['comments'][0]['internal'])->toBeTrue();
})->with(['technician', 'manager', 'admin']);

test('comments are listed in chronological order', function () {
    $later = TicketComment::factory()->for($this->ticket)->create(['created_at' => now()->subMinute()]);
    $earlier = TicketComment::factory()->for($this->ticket)->create(['created_at' => now()->subHour()]);

    $response = $this->actingAs($this->requester)->get(showUrl($this->ticket));

    expect(collect(detailProps($response)['comments'])->pluck('id')->all())->toBe([$earlier->id, $later->id])
        ->and(array_keys(detailProps($response)['comments'][0]))->toBe(['id', 'author', 'body', 'internal', 'created_at']);
});

test('the requester adds a public comment as the authenticated author', function () {
    $other = User::factory()->create();
    $otherTicket = Ticket::factory()->create();
    $before = $this->ticket->updated_at;
    $this->travel(5)->minutes();

    $this->actingAs($this->requester)
        ->post(showUrl($this->ticket).'/comments', [
            'body' => 'Ainda sem impressão.',
            'user_id' => $other->id,
            'ticket_id' => $otherTicket->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tickets.show', $this->ticket->ticket_number));

    $comment = TicketComment::sole();

    expect($comment->user_id)->toBe($this->requester->id)
        ->and($comment->ticket_id)->toBe($this->ticket->id)
        ->and($comment->visibility)->toBe(CommentVisibility::Public)
        ->and($this->ticket->fresh()->updated_at->gt($before))->toBeTrue();
});

test('requesters cannot post internal notes by manipulating the request', function () {
    $this->actingAs($this->requester)
        ->post(showUrl($this->ticket).'/comments', ['body' => 'Tentativa', 'visibility' => 'internal'])
        ->assertForbidden();

    expect(TicketComment::count())->toBe(0);
});

test('technicians can post internal notes', function () {
    $tech = User::factory()->withRole('technician')->create();

    $this->actingAs($tech)
        ->get(showUrl($this->ticket))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.comment', true)
            ->where('can.addInternalNote', true));

    $this->actingAs($tech)
        ->post(showUrl($this->ticket).'/comments', ['body' => 'Verificar estoque', 'visibility' => 'internal'])
        ->assertSessionHasNoErrors();

    expect(TicketComment::sole()->visibility)->toBe(CommentVisibility::Internal);
});

test('administrators see internal notes but cannot add them by default', function () {
    $admin = User::factory()->withRole('admin')->create();

    $this->actingAs($admin)
        ->post(showUrl($this->ticket).'/comments', ['body' => 'Nota', 'visibility' => 'internal'])
        ->assertForbidden();
});

test('invalid messages are rejected without creating a comment', function (array $payload, string $field) {
    $this->actingAs($this->requester)
        ->post(showUrl($this->ticket).'/comments', $payload)
        ->assertSessionHasErrors($field);

    expect(TicketComment::count())->toBe(0);
})->with([
    'vazio' => [['body' => '   '], 'body'],
    'longo' => [['body' => str_repeat('a', TicketComment::BODY_MAX_LENGTH + 1)], 'body'],
    'tipo desconhecido' => [['body' => 'Olá', 'visibility' => 'secreto'], 'visibility'],
]);

test('users without access cannot comment', function () {
    $this->actingAs(User::factory()->withRole('requester')->create())
        ->post(showUrl($this->ticket).'/comments', ['body' => 'Intruso'])
        ->assertForbidden();

    expect(TicketComment::count())->toBe(0);
});

test('closed tickets do not accept new messages', function () {
    $this->ticket->forceFill(['status' => TicketStatus::Closed])->save();

    $this->actingAs($this->requester)
        ->get(showUrl($this->ticket))
        ->assertInertia(fn (Assert $page) => $page->where('can.comment', false));

    $this->actingAs($this->requester)
        ->post(showUrl($this->ticket).'/comments', ['body' => 'Mais uma coisa'])
        ->assertForbidden();
});

/* ---------- SLA ---------- */

test('SLA indicators only reflect instants stored by the backend', function () {
    $this->actingAs($this->requester)
        ->get(showUrl($this->ticket))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sla.firstResponse', ['state' => 'none', 'due_at' => null, 'met_at' => null])
            ->where('sla.resolution', ['state' => 'none', 'due_at' => null, 'met_at' => null]));

    $this->ticket->forceFill([
        'first_response_due_at' => now()->subHour(),
        'first_response_at' => now()->subHours(2),
        'resolution_due_at' => now()->subMinute(),
    ])->save();

    $this->actingAs($this->requester)
        ->get(showUrl($this->ticket))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sla.firstResponse.state', 'met')
            ->where('sla.resolution.state', 'overdue'));
});

/* ---------- Ações inexistentes ---------- */

test('no lifecycle or deletion operation is reachable by direct requests', function () {
    $tech = User::factory()->withRole('technician')->create();
    $comment = TicketComment::factory()->for($this->ticket)->create();

    foreach (['put', 'delete'] as $method) {
        $this->actingAs($tech)->{$method}(showUrl($this->ticket), ['status' => 'resolved'])->assertMethodNotAllowed();
    }
    $this->actingAs($tech)->delete(showUrl($this->ticket)."/comments/{$comment->id}")->assertNotFound();

    // PATCH é a edição operacional (CHA-04): status e responsável não fazem parte do contrato.
    $this->actingAs($tech)->patch(showUrl($this->ticket), [
        'status' => 'resolved',
        'assigned_to' => $tech->id,
        'version' => $this->ticket->editVersion(),
    ]);
    expect($this->ticket->fresh()->assigned_to)->toBeNull();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and($comment->fresh())->not->toBeNull();
});
