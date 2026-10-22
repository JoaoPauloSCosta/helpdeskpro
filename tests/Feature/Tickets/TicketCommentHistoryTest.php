<?php

use App\Actions\Tickets\EditTicketComment;
use App\Enums\CommentVisibility;
use App\Enums\TicketEvent;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketCommentVersion;
use App\Models\TicketHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza']);
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Bruno Lima']);
    // Aberto e sem responsável: visível ao solicitante e ao técnico (autoatribuição).
    $this->ticket = Ticket::factory()->create(['requester_id' => $this->requester->id]);
});

function versionsUrl(Ticket $ticket, TicketComment|int $comment): string
{
    $id = $comment instanceof TicketComment ? $comment->id : $comment;

    return "/tickets/{$ticket->ticket_number}/comments/{$id}/versions";
}

function editComment(User $editor, TicketComment $comment, string $body): void
{
    app(EditTicketComment::class)($editor, $comment, $body);
}

/* ---------- Versionamento na edição ---------- */

test('an edit preserves the previous content, editor and server time before replacing it', function () {
    $comment = TicketComment::factory()->for($this->ticket)->create(['user_id' => $this->tech->id, 'body' => 'Texto original']);
    $this->travelTo(Carbon::parse('2026-10-21 10:30:00'));

    editComment($this->tech, $comment, 'Texto corrigido');

    $version = TicketCommentVersion::sole();
    expect($version->ticket_comment_id)->toBe($comment->id)
        ->and($version->previous_body)->toBe('Texto original')
        ->and($version->previous_visibility)->toBe(CommentVisibility::Public)
        ->and($version->edited_by)->toBe($this->tech->id)
        ->and($version->edited_at->toDateTimeString())->toBe('2026-10-21 10:30:00')
        ->and($comment->fresh()->body)->toBe('Texto corrigido')
        ->and($comment->body)->toBe('Texto corrigido');
});

test('an edit without changes records no version and no audit event', function () {
    $comment = TicketComment::factory()->for($this->ticket)->create(['body' => 'Mesmo texto']);

    editComment($this->tech, $comment, 'Mesmo texto');

    expect(TicketCommentVersion::count())->toBe(0)
        ->and(TicketHistory::count())->toBe(0);
});

test('a failure while saving the edit leaves the comment, its versions and the audit untouched', function () {
    $comment = TicketComment::factory()->for($this->ticket)->create(['body' => 'Texto original']);
    TicketComment::updating(fn () => throw new RuntimeException('Falha simulada'));

    expect(fn () => editComment($this->tech, $comment, 'Texto novo'))->toThrow(RuntimeException::class);

    expect(DB::table('ticket_comments')->where('id', $comment->id)->value('body'))->toBe('Texto original')
        ->and(TicketCommentVersion::count())->toBe(0)
        ->and(TicketHistory::count())->toBe(0);
});

test('each edit is audited once, without copying the comment content', function () {
    $comment = TicketComment::factory()->for($this->ticket)->internal()->create(['body' => 'Senha do roteador']);

    editComment($this->tech, $comment, 'Senha trocada');

    $event = TicketHistory::sole();
    expect($event->event_type)->toBe(TicketEvent::CommentEdited)
        ->and($event->ticket_id)->toBe($this->ticket->id)
        ->and($event->user_id)->toBe($this->tech->id)
        ->and($event->new_values)->toBe([
            'comment_id' => $comment->id,
            'version_id' => TicketCommentVersion::sole()->id,
            'visibility' => 'internal',
        ])
        ->and(json_encode([$event->old_values, $event->new_values]))->not->toContain('Senha');

    // Na tela de detalhes, quem tem `audit.view` vê o evento uma única vez.
    $manager = User::factory()->withRole('manager')->create();
    $history = $this->actingAs($manager)->get("/tickets/{$this->ticket->ticket_number}")
        ->viewData('page')['props']['history'];

    expect(collect($history)->where('title', 'Comentário editado'))->toHaveCount(1);
});

/* ---------- Consulta ---------- */

test('an authorized user reads every version of the comment, newest first', function () {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    $comment = TicketComment::factory()->for($this->ticket)->create(['user_id' => $this->tech->id, 'body' => 'Versão A']);
    $manager = User::factory()->withRole('manager')->create(['name' => 'Carla Dias']);

    $this->travelTo(Carbon::parse('2026-10-21 09:15:00'));
    editComment($this->tech, $comment, 'Versão B');
    $this->travelTo(Carbon::parse('2026-10-21 09:40:00'));
    editComment($manager, $comment, 'Versão C');

    $response = $this->actingAs($this->requester)->getJson(versionsUrl($this->ticket, $comment))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    $versions = $response->json('versions');

    expect($response->json('ticket'))->toBe($this->ticket->ticket_number)
        ->and($response->json('internal'))->toBeFalse()
        ->and(array_column($versions, 'number'))->toBe([3, 2, 1])
        ->and(array_column($versions, 'body'))->toBe(['Versão C', 'Versão B', 'Versão A'])
        // Quem escreveu cada texto: o autor original e, depois, quem fez cada edição.
        ->and(array_column($versions, 'author'))->toBe(['Carla Dias', 'Bruno Lima', 'Bruno Lima'])
        ->and(array_map(fn ($at) => Carbon::parse($at)->format('H:i'), array_column($versions, 'recorded_at')))->toBe(['09:40', '09:15', '09:00'])
        ->and(array_column($versions, 'current'))->toBe([true, false, false])
        ->and(array_column($versions, 'original'))->toBe([false, false, true])
        // A versão mais recente é exatamente o conteúdo armazenado no comentário.
        ->and($versions[0]['body'])->toBe($comment->fresh()->body)
        // Somente os campos necessários: nenhuma chave interna ou dado de usuário.
        ->and(array_keys($versions[0]))->toBe(['number', 'body', 'internal', 'author', 'recorded_at', 'original', 'current']);
});

test('the history only contains versions of the requested comment', function () {
    $first = TicketComment::factory()->for($this->ticket)->create(['body' => 'Primeiro original']);
    $second = TicketComment::factory()->for($this->ticket)->create(['body' => 'Segundo original']);
    editComment($this->tech, $first, 'Primeiro editado');
    editComment($this->tech, $second, 'Segundo editado');

    $response = $this->actingAs($this->requester)->getJson(versionsUrl($this->ticket, $first))->assertOk();

    expect(array_column($response->json('versions'), 'body'))->toBe(['Primeiro editado', 'Primeiro original'])
        ->and($response->getContent())->not->toContain('Segundo');
});

test('a comment without stored versions returns only its current content', function () {
    $comment = TicketComment::factory()->for($this->ticket)->create(['body' => 'Nunca editado']);

    $response = $this->actingAs($this->requester)->getJson(versionsUrl($this->ticket, $comment))->assertOk();

    expect($response->json('versions'))->toHaveCount(1)
        ->and($response->json('versions.0'))->toMatchArray([
            'number' => 1, 'body' => 'Nunca editado', 'original' => true, 'current' => true,
        ]);
});

test('older comments changed without stored content do not receive invented versions', function () {
    $comment = TicketComment::factory()->for($this->ticket)->create([
        'body' => 'Texto alterado fora do versionamento',
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDay(),
    ]);

    $this->actingAs($this->requester)->getJson(versionsUrl($this->ticket, $comment))
        ->assertOk()
        ->assertJsonCount(1, 'versions');

    $this->actingAs($this->requester)->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn ($page) => $page->where('comments.0.edited', false));
});

test('the details page flags edited comments without sending previous content', function () {
    $edited = TicketComment::factory()->for($this->ticket)->create(['body' => 'Rascunho antigo']);
    TicketComment::factory()->for($this->ticket)->create(['body' => 'Sem edição']);
    editComment($this->tech, $edited, 'Texto final');

    $response = $this->actingAs($this->requester)->get("/tickets/{$this->ticket->ticket_number}")->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->where('comments.0.edited', true)
        ->where('comments.0.body', 'Texto final')
        ->where('comments.1.edited', false));
    expect($response->getContent())->not->toContain('Rascunho antigo');
});

/* ---------- Autorização ---------- */

test('guests are redirected and inactive users are denied', function () {
    $comment = TicketComment::factory()->for($this->ticket)->create();

    $this->get(versionsUrl($this->ticket, $comment))->assertRedirect('/login');

    $this->requester->forceFill(['is_active' => false])->save();
    $this->actingAs($this->requester)->getJson(versionsUrl($this->ticket, $comment))->assertForbidden();
});

test('users without access to the ticket cannot read the history', function () {
    $comment = TicketComment::factory()->for($this->ticket)->create(['body' => 'Conteúdo original']);
    editComment($this->tech, $comment, 'Conteúdo atual');
    $stranger = User::factory()->withRole('requester')->create();

    $response = $this->actingAs($stranger)->getJson(versionsUrl($this->ticket, $comment))->assertForbidden();

    expect($response->getContent())->not->toContain('Conteúdo')
        ->and($response->json())->not->toHaveKey('versions');
});

test('requesters cannot read the history of internal notes', function () {
    $note = TicketComment::factory()->for($this->ticket)->internal()->create(['body' => 'Nota original']);
    editComment($this->tech, $note, 'Nota revisada');

    // Responde como inexistente: nem o conteúdo nem a existência da nota são revelados.
    $response = $this->actingAs($this->requester)->getJson(versionsUrl($this->ticket, $note))->assertNotFound();
    expect($response->getContent())->not->toContain('Nota');

    // O mesmo pedido, pela equipe com `tickets.view_internal_notes`, é atendido.
    $this->actingAs($this->tech)->getJson(versionsUrl($this->ticket, $note))
        ->assertOk()
        ->assertJsonPath('internal', true)
        ->assertJsonPath('versions.1.body', 'Nota original')
        ->assertJsonPath('versions.1.internal', true);
});

test('the details page never sends internal notes or their versions to requesters', function () {
    $note = TicketComment::factory()->for($this->ticket)->internal()->create(['body' => 'Diagnóstico interno']);
    editComment($this->tech, $note, 'Diagnóstico revisado');

    $response = $this->actingAs($this->requester)->get("/tickets/{$this->ticket->ticket_number}")->assertOk();

    $response->assertInertia(fn ($page) => $page->has('comments', 0));
    expect($response->getContent())->not->toContain('Diagnóstico');
});

test('versions that were internal stay hidden from requesters even on a public comment', function () {
    // Mudança de visibilidade ainda não existe; a regra é aplicada a cada versão mesmo assim.
    $comment = TicketComment::factory()->for($this->ticket)->create(['body' => 'Texto público']);
    $comment->versions()->forceCreate([
        'edited_by' => $this->tech->id,
        'previous_body' => 'Texto que era interno',
        'previous_visibility' => CommentVisibility::Internal,
        'edited_at' => now(),
    ]);

    $response = $this->actingAs($this->requester)->getJson(versionsUrl($this->ticket, $comment))->assertOk();

    expect(array_column($response->json('versions'), 'body'))->toBe(['Texto público'])
        ->and($response->getContent())->not->toContain('era interno');

    $this->actingAs($this->requester)->get("/tickets/{$this->ticket->ticket_number}")
        ->assertInertia(fn ($page) => $page->where('comments.0.edited', false));

    $this->actingAs($this->tech)->getJson(versionsUrl($this->ticket, $comment))->assertJsonCount(2, 'versions');
});

test('comments from another ticket cannot be read by changing identifiers', function () {
    $other = Ticket::factory()->create(['requester_id' => $this->requester->id]);
    $foreign = TicketComment::factory()->for($other)->create(['body' => 'Mensagem de outro chamado']);
    editComment($this->tech, $foreign, 'Mensagem editada');

    // Mesmo com acesso aos dois chamados, o comentário precisa pertencer ao chamado da URL.
    $response = $this->actingAs($this->requester)->getJson(versionsUrl($this->ticket, $foreign))->assertNotFound();
    expect($response->getContent())->not->toContain('Mensagem');

    // Sem acesso ao chamado do comentário, a combinação também não revela nada.
    $hidden = Ticket::factory()->create();
    $hiddenComment = TicketComment::factory()->for($hidden)->create(['body' => 'Mensagem oculta']);
    $this->actingAs($this->requester)->getJson(versionsUrl($this->ticket, $hiddenComment))->assertNotFound();
    $this->actingAs($this->requester)->getJson(versionsUrl($hidden, $hiddenComment))->assertForbidden();

    $this->actingAs($this->requester)->getJson(versionsUrl($this->ticket, 999999))->assertNotFound();
    $this->actingAs($this->requester)->getJson("/tickets/{$this->ticket->ticket_number}/comments/abc/versions")->assertNotFound();
});

test('the history is read only', function () {
    $comment = TicketComment::factory()->for($this->ticket)->create(['body' => 'Original']);
    editComment($this->tech, $comment, 'Atual');

    foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
        $this->actingAs($this->tech)->{$method}(versionsUrl($this->ticket, $comment), ['previous_body' => 'Adulterado'])
            ->assertMethodNotAllowed();
    }

    expect(TicketCommentVersion::sole()->previous_body)->toBe('Original')
        ->and($comment->fresh()->body)->toBe('Atual');
});
