<?php

use App\Actions\Tickets\AssignTicket;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->department = Department::factory()->create(['name' => 'Financeiro']);
    $this->category = TicketCategory::factory()->create(['name' => 'Hardware']);
    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza', 'department_id' => $this->department->id]);
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create(['name' => 'Marina Souza']);

    $this->ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'assigned_to' => $this->tech->id,
        'requester_id' => $this->requester->id,
        'department_id' => $this->department->id,
        'priority' => TicketPriority::Medium,
    ]);

    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
});

function auditUrl(Ticket $ticket, array $query = []): string
{
    return "/tickets/{$ticket->ticket_number}/audit".($query ? '?'.http_build_query($query) : '');
}

function auditEvents(User $user, Ticket $ticket, array $query = []): array
{
    return test()->actingAs($user)->getJson(auditUrl($ticket, $query))->assertOk()->json('events');
}

/** Papel avulso com exatamente as permissões indicadas. */
function auditorWithPermissions(array $slugs): User
{
    $role = Role::create(['name' => 'Papel de teste '.uniqid(), 'slug' => 'test-'.uniqid()]);
    $role->permissions()->attach(Permission::whereIn('slug', $slugs)->pluck('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/** Evento gravado diretamente, para cenários que os fluxos não produzem (payloads incompletos). */
function auditFixture(Ticket $ticket, TicketEvent $type, ?array $old, ?array $new, ?User $author = null, ?string $at = null): TicketHistory
{
    return $ticket->histories()->forceCreate([
        'user_id' => $author?->id,
        'event_type' => $type,
        'old_values' => $old,
        'new_values' => $new,
        'created_at' => $at ? Carbon::parse($at) : now(),
    ]);
}

function auditDetail(array $event, string $label): mixed
{
    return collect($event['details'])->firstWhere('label', $label)['value'] ?? null;
}

/* ---------- Acesso e autorização ---------- */

test('guests are redirected to login', function () {
    $this->get(auditUrl($this->ticket))->assertRedirect('/login');
});

test('users with audit access read the audit of a ticket they can see', function () {
    // Gestor (matriz inicial) e um papel avulso que só enxerga os próprios chamados.
    $auditor = auditorWithPermissions(['audit.view']);
    $own = Ticket::factory()->create(['requester_id' => $auditor->id]);

    $this->actingAs($this->manager)
        ->getJson(auditUrl($this->ticket))
        ->assertOk()
        ->assertJsonPath('ticket', $this->ticket->ticket_number)
        ->assertJsonPath('events', [])
        ->assertJsonPath('meta.total', 0)
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->actingAs($auditor)->getJson(auditUrl($own))->assertOk()->assertJsonPath('ticket', $own->ticket_number);
});

test('the details page tells whether the audit can be consulted', function () {
    $this->actingAs($this->manager)
        ->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page->where('can.viewAudit', true)->missing('history'));

    foreach ([$this->requester, $this->tech] as $user) {
        $this->actingAs($user)
            ->get("/tickets/{$this->ticket->ticket_number}")
            ->assertInertia(fn (Assert $page) => $page->where('can.viewAudit', false)->missing('history'));
    }
});

test('users without audit.view cannot read the audit, even with access to the ticket', function () {
    $this->ticket->histories()->forceCreate(['user_id' => $this->tech->id, 'event_type' => TicketEvent::Closed, 'new_values' => ['label' => 'Encerrado']]);

    // Solicitante original, técnico responsável e administrador sem a permissão (matriz configurável).
    $noAudit = auditorWithPermissions(['tickets.view_all_departments', 'tickets.view_internal_notes']);

    foreach ([$this->requester, $this->tech, $noAudit] as $user) {
        $this->actingAs($user)->getJson(auditUrl($this->ticket))->assertForbidden()->assertJsonMissing(['title' => 'Chamado encerrado']);
    }
});

test('audit.view does not widen the ticket scope', function () {
    $outsider = auditorWithPermissions(['audit.view']);
    auditFixture($this->ticket, TicketEvent::Closed, null, ['label' => 'Encerrado'], $this->tech);

    $this->actingAs($outsider)->getJson(auditUrl($this->ticket))->assertForbidden()->assertJsonMissing(['author' => 'Carlos Lima']);
    $this->actingAs($outsider)->get("/tickets/{$this->ticket->ticket_number}")->assertForbidden();
});

test('inactive users cannot read the audit', function () {
    $this->manager->forceFill(['is_active' => false])->save();

    $this->actingAs($this->manager)->getJson(auditUrl($this->ticket))->assertForbidden();
});

test('unknown identifiers and internal keys do not resolve to a ticket', function () {
    $this->actingAs($this->manager)->getJson('/tickets/HD-ZZZZZZZZ/audit')->assertNotFound();
    $this->actingAs($this->manager)->getJson("/tickets/{$this->ticket->id}/audit")->assertNotFound();
});

/* ---------- Escopo, ordem e paginação ---------- */

test('only events of the requested ticket are returned', function () {
    $other = Ticket::factory()->create(['requester_id' => $this->requester->id]);
    $mine = auditFixture($this->ticket, TicketEvent::Closed, ['label' => 'Resolvido'], ['label' => 'Encerrado'], $this->manager);
    auditFixture($other, TicketEvent::Reopened, ['label' => 'Resolvido'], ['label' => 'Aberto', 'justification' => 'Outro chamado.'], $this->requester);

    $events = auditEvents($this->manager, $this->ticket);

    expect(collect($events)->pluck('id')->all())->toBe([$mine->id])
        ->and(json_encode($events))->not->toContain('Outro chamado.');
});

test('events are listed chronologically with a stable order for the same instant', function () {
    $late = auditFixture($this->ticket, TicketEvent::Closed, null, ['label' => 'Encerrado'], null, '2026-10-21 10:00:00');
    $first = auditFixture($this->ticket, TicketEvent::PriorityChanged, ['label' => 'Média'], ['label' => 'Alta'], null, '2026-10-21 09:00:00');
    $second = auditFixture($this->ticket, TicketEvent::CategoryChanged, ['label' => 'Hardware'], ['label' => 'Rede'], null, '2026-10-21 09:00:00');

    expect(collect(auditEvents($this->manager, $this->ticket))->pluck('id')->all())->toBe([$first->id, $second->id, $late->id]);
});

test('the history is paginated on the server without gaps or repetitions', function () {
    foreach (range(1, 25) as $minute) {
        auditFixture($this->ticket, TicketEvent::StatusChanged, ['label' => 'Pendente'], ['label' => 'Em atendimento'], $this->tech, "2026-10-21 09:{$minute}:00");
    }

    $first = $this->actingAs($this->manager)->getJson(auditUrl($this->ticket))->assertOk();
    $second = $this->actingAs($this->manager)->getJson(auditUrl($this->ticket, ['page' => 2]))->assertOk();

    $first->assertJsonCount(20, 'events')->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => 20, 'total' => 25]);
    $second->assertJsonCount(5, 'events')->assertJsonPath('meta.current_page', 2);

    $ids = [...collect($first->json('events'))->pluck('id'), ...collect($second->json('events'))->pluck('id')];

    expect($ids)->toBe(TicketHistory::orderBy('created_at')->orderBy('id')->pluck('id')->all());
});

/* ---------- Conteúdo dos eventos ---------- */

test('previous and new values are presented as recorded', function () {
    $other = User::factory()->withRole('technician')->create(['name' => 'Beatriz Rocha']);
    $ticket = Ticket::factory()->create(['requester_id' => $this->requester->id]);

    app(AssignTicket::class)($this->manager, $ticket, $this->tech->id, null);
    $this->travel(1)->minutes();
    app(AssignTicket::class)($this->manager, $ticket->fresh(), $other->id, $this->tech->id);

    $events = auditEvents($this->manager, $ticket);

    expect($events[0])->toMatchArray([
        'type' => 'assigned',
        'title' => 'Técnico atribuído',
        'author' => 'Marina Souza',
        'changes' => [['field' => 'Responsável', 'from' => 'Sem responsável', 'to' => 'Carlos Lima']],
    ])->and($events[1])->toMatchArray([
        'type' => 'reassigned',
        'changes' => [['field' => 'Responsável', 'from' => 'Carlos Lima', 'to' => 'Beatriz Rocha']],
    ]);
});

test('each event exposes only the presentation fields', function () {
    auditFixture($this->ticket, TicketEvent::PriorityChanged, ['priority' => 'medium', 'label' => 'Média'], ['priority' => 'high', 'label' => 'Alta'], $this->manager);

    $event = auditEvents($this->manager, $this->ticket)[0];

    expect(array_keys($event))->toBe(['id', 'type', 'title', 'tone', 'created_at', 'author', 'description', 'changes', 'details', 'restricted'])
        ->and($event['created_at'])->toBe(now()->toIso8601String())
        // Valores técnicos e chaves internas do payload não são repassados.
        ->and(json_encode($event))->not->toContain('"medium"')
        ->and(json_encode($event))->not->toContain('old_values');
});

test('events with missing optional fields do not cause errors', function () {
    foreach (TicketEvent::cases() as $type) {
        auditFixture($this->ticket, $type, null, null);
        auditFixture($this->ticket, $type, [], ['sla_pause' => []]);
    }

    $events = [...auditEvents($this->manager, $this->ticket), ...auditEvents($this->manager, $this->ticket, ['page' => 2])];

    expect($events)->toHaveCount(count(TicketEvent::cases()) * 2);

    foreach ($events as $event) {
        expect($event['author'])->toBeNull()
            ->and($event['title'])->not->toBe('')
            ->and($event['details'])->toBe([]);

        // Valores não gravados chegam como nulos, nunca inventados.
        foreach ($event['changes'] as $change) {
            expect($change['to'])->toBeNull();
        }
    }
});

test('SLA target changes and breaches are presented from the recorded values', function () {
    auditFixture($this->ticket, TicketEvent::SlaTargetsChanged, [
        'priority' => ['label' => 'Média'],
        'first_response_minutes' => null,
        'resolution_minutes' => 480,
        'resolution_due_at' => '2026-10-21T16:00:00+00:00',
    ], [
        'priority' => ['label' => 'Alta'],
        'first_response_minutes' => 30,
        'resolution_minutes' => 240,
        'resolution_due_at' => '2026-10-21T12:00:00+00:00',
        'consumed_seconds' => 18000,
    ]);
    auditFixture($this->ticket, TicketEvent::SlaBreached, null, [
        'indicator' => 'resolution',
        'due_at' => '2026-10-21T12:00:00+00:00',
        'breached_at' => '2026-10-21T12:01:00+00:00',
    ]);

    [$targets, $breach] = auditEvents($this->manager, $this->ticket);

    expect($targets['title'])->toBe('Metas de SLA recalculadas')
        ->and($targets['changes'])->toBe([
            ['field' => 'Prioridade', 'from' => 'Média', 'to' => 'Alta'],
            ['field' => 'Meta de primeira resposta', 'from' => 'Sem prazo definido', 'to' => '30 min'],
            ['field' => 'Meta de resolução', 'from' => '480 min', 'to' => '240 min'],
        ])
        ->and(auditDetail($targets, 'Tempo de resolução consumido'))->toBe(18000)
        ->and($breach['title'])->toBe('Resolução fora do prazo')
        ->and($breach['tone'])->toBe('danger')
        ->and(auditDetail($breach, 'Prazo violado'))->toBe('2026-10-21T12:00:00+00:00');
});

test('sensitive keys present in a payload are never returned', function () {
    auditFixture($this->ticket, TicketEvent::StatusChanged, ['label' => 'Pendente', 'password' => 'segredo-antigo'], [
        'label' => 'Em atendimento',
        'token' => 'tok_123456',
        'api_key' => 'chave-secreta',
        'remember_token' => 'lembrar-123',
    ], $this->tech);

    $body = $this->actingAs($this->manager)->getJson(auditUrl($this->ticket))->assertOk()->getContent();

    foreach (['segredo-antigo', 'tok_123456', 'chave-secreta', 'lembrar-123', 'password', 'api_key'] as $secret) {
        expect($body)->not->toContain($secret);
    }

    // Nem dados do autor além do nome.
    expect($body)->not->toContain($this->tech->email);
});

test('pending details are restricted to the support team', function () {
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ticket_number}/pending", [
        'pause_status' => 'pending_third_party',
        'reason' => 'Aguardando peça do fornecedor.',
        'supplier_name' => 'Fornecedor XPTO',
        'expected_status' => 'in_progress',
    ])->assertSessionHasNoErrors();

    $support = auditEvents($this->manager, $this->ticket)[0];

    expect($support)->toMatchArray(['type' => 'pending_registered', 'tone' => 'warning', 'restricted' => false])
        ->and(auditDetail($support, 'Motivo'))->toBe('Aguardando peça do fornecedor.')
        ->and(auditDetail($support, 'Fornecedor ou terceiro'))->toBe('Fornecedor XPTO');

    // `audit.view` e visibilidade global, mas fora da equipe de suporte (sem `tickets.view_internal_notes`).
    $auditor = auditorWithPermissions(['audit.view', 'tickets.view_all_departments']);
    $restricted = auditEvents($auditor, $this->ticket)[0];

    expect($restricted['restricted'])->toBeTrue()
        ->and($restricted['changes'])->toBe([['field' => 'Status', 'from' => 'Em atendimento', 'to' => 'Pendente Terceiros']])
        ->and(auditDetail($restricted, 'Início da pausa'))->toBe(now()->toIso8601String())
        ->and(json_encode($restricted))->not->toContain('Aguardando peça')
        ->and(json_encode($restricted))->not->toContain('XPTO');
});

test('internal note events do not exist for users who cannot see internal notes', function () {
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ticket_number}/comments", ['body' => 'Senha do roteador: 1234', 'visibility' => 'internal'])->assertSessionHasNoErrors();
    $this->travel(1)->minutes();
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ticket_number}/comments", ['body' => 'Estamos verificando.'])->assertSessionHasNoErrors();

    $support = auditEvents($this->manager, $this->ticket);

    expect(collect($support)->pluck('title')->all())->toBe(['Nota interna adicionada', 'Comentário público adicionado']);

    $auditor = auditorWithPermissions(['audit.view', 'tickets.view_all_departments']);
    $response = $this->actingAs($auditor)->getJson(auditUrl($this->ticket))->assertOk();

    expect(collect($response->json('events'))->pluck('title')->all())->toBe(['Comentário público adicionado'])
        ->and($response->json('meta.total'))->toBe(1);

    // O texto das mensagens nunca está na auditoria, nem para a equipe de suporte.
    expect(json_encode($support))->not->toContain('Senha do roteador')
        ->and(json_encode($support))->not->toContain('Estamos verificando');
});

/* ---------- Registro pelos fluxos (RF-19) ---------- */

test('opening a ticket records a single creation event with the initial values', function () {
    $this->actingAs($this->requester)->post('/tickets', [
        'title' => 'Impressora sem toner',
        'description' => 'Descrição que não deve ir para a auditoria.',
        'category_id' => $this->category->id,
        'priority' => 'high',
        'submission_token' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $ticket = Ticket::where('title', 'Impressora sem toner')->sole();
    $stored = $ticket->histories()->sole();

    expect($stored->event_type)->toBe(TicketEvent::Created)
        ->and($stored->user_id)->toBe($this->requester->id)
        ->and($stored->created_at->equalTo($ticket->created_at))->toBeTrue()
        ->and(json_encode($stored->new_values))->not->toContain('Descrição que não deve');

    $event = auditEvents($this->manager, $ticket)[0];

    expect($event)->toMatchArray([
        'type' => 'created',
        'title' => 'Chamado aberto',
        'author' => 'Ana Souza',
        'changes' => [
            ['field' => 'Status inicial', 'to' => 'Aberto'],
            ['field' => 'Prioridade', 'to' => 'Alta'],
            ['field' => 'Categoria', 'to' => 'Hardware'],
            ['field' => 'Departamento', 'to' => 'Financeiro'],
        ],
    ])->and(auditDetail($event, 'Título'))->toBe('Impressora sem toner');
});

test('adding a comment records a single event without its text', function () {
    $this->actingAs($this->requester)->post("/tickets/{$this->ticket->ticket_number}/comments", ['body' => 'Ainda sem rede.'])->assertSessionHasNoErrors();

    $comment = TicketComment::sole();
    $stored = TicketHistory::sole();

    expect($stored->event_type)->toBe(TicketEvent::CommentAdded)
        ->and($stored->ticket_id)->toBe($this->ticket->id)
        ->and($stored->user_id)->toBe($this->requester->id)
        ->and($stored->new_values)->toBe(['comment_id' => $comment->id, 'visibility' => 'public'])
        ->and($stored->created_at->equalTo($comment->created_at))->toBeTrue();
});

test('the full ticket lifecycle is recorded once per operation, in order', function () {
    $number = fn () => Ticket::sole()->ticket_number;
    $step = function (User $user, string $method, string $path, array $data = []) use ($number) {
        $this->travel(1)->minutes();
        $this->actingAs($user)->{$method}("/tickets/{$number()}{$path}", $data)->assertSessionHasNoErrors();
    };

    Ticket::query()->delete();
    $this->actingAs($this->requester)->post('/tickets', [
        'title' => 'Sem acesso ao ERP',
        'description' => 'Erro ao entrar.',
        'category_id' => $this->category->id,
        'priority' => 'medium',
        'submission_token' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $step($this->tech, 'post', '/self-assignment');
    $step($this->tech, 'post', '/comments', ['body' => 'Verificando o acesso.']);
    $step($this->tech, 'post', '/pending', ['pause_status' => 'awaiting_requester', 'reason' => 'Aguardando print do erro.', 'expected_status' => 'in_progress']);
    $step($this->tech, 'put', '/status', ['status' => 'in_progress', 'expected_status' => 'awaiting_requester']);
    $step($this->tech, 'post', '/resolution', ['resolution_summary' => 'Senha redefinida.', 'expected_status' => 'in_progress']);
    $step($this->requester, 'post', '/reopening', ['justification' => 'Voltou a falhar.', 'expected_status' => 'resolved']);
    $step($this->tech, 'put', '/status', ['status' => 'in_progress', 'expected_status' => 'open']);
    $step($this->tech, 'post', '/resolution', ['resolution_summary' => 'Perfil recriado.', 'expected_status' => 'in_progress']);
    $step($this->manager, 'post', '/closing', ['expected_status' => 'resolved']);

    $events = auditEvents($this->manager, Ticket::sole());

    expect(collect($events)->pluck('type')->all())->toBe([
        'created', 'self_assigned', 'comment_added', 'pending_registered', 'status_changed',
        'resolved', 'reopened', 'status_changed', 'resolved', 'closed',
    ])->and(collect($events)->pluck('author')->all())->toBe([
        'Ana Souza', 'Carlos Lima', 'Carlos Lima', 'Carlos Lima', 'Carlos Lima',
        'Carlos Lima', 'Ana Souza', 'Carlos Lima', 'Carlos Lima', 'Marina Souza',
    ])
        // Retomada: o fim da pausa e a duração gravados no evento.
        ->and($events[4]['description'])->toBe('Pausa do SLA de resolução encerrada.')
        ->and(auditDetail($events[4], 'Duração da pausa'))->toBe(60)
        // A segunda resolução preserva a anterior (RF-12).
        ->and(auditDetail($events[8], 'Solução anterior'))->toBe('Senha redefinida.')
        ->and($events[9]['tone'])->toBe('neutral');
});

test('a failure while recording the creation event leaves no ticket behind', function () {
    TicketHistory::creating(fn () => throw new RuntimeException('falha simulada na auditoria'));
    Ticket::query()->delete();

    expect(fn () => $this->withoutExceptionHandling()->actingAs($this->requester)->post('/tickets', [
        'title' => 'Chamado sem auditoria',
        'description' => 'Não deve ser gravado.',
        'category_id' => $this->category->id,
        'priority' => 'low',
        'submission_token' => (string) Str::uuid(),
    ]))->toThrow(RuntimeException::class);

    expect(Ticket::count())->toBe(0)->and(TicketHistory::count())->toBe(0);
});

test('a failure while recording the comment event leaves no comment behind', function () {
    TicketHistory::creating(fn () => throw new RuntimeException('falha simulada na auditoria'));

    expect(fn () => $this->withoutExceptionHandling()->actingAs($this->requester)
        ->post("/tickets/{$this->ticket->ticket_number}/comments", ['body' => 'Não deve ser gravado.']))
        ->toThrow(RuntimeException::class);

    expect(TicketComment::count())->toBe(0)->and(TicketHistory::count())->toBe(0);
});

/* ---------- Somente leitura ---------- */

test('the audit is read only', function () {
    auditFixture($this->ticket, TicketEvent::Closed, ['label' => 'Resolvido'], ['label' => 'Encerrado'], $this->manager);
    $before = TicketHistory::all()->map->getAttributes()->all();
    $ticketBefore = $this->ticket->fresh()->getAttributes();

    auditEvents($this->manager, $this->ticket);
    auditEvents($this->manager, $this->ticket, ['page' => 2]);

    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->actingAs($this->manager)->{$method.'Json'}(auditUrl($this->ticket), [
            'user_id' => $this->tech->id,
            'created_at' => '2020-01-01 00:00:00',
            'new_values' => ['label' => 'Forjado'],
        ])->assertMethodNotAllowed();
    }

    expect(TicketHistory::all()->map->getAttributes()->all())->toBe($before)
        ->and($this->ticket->fresh()->getAttributes())->toBe($ticketBefore);
});
