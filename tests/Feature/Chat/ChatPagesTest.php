<?php

use App\Models\ChatAgent;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Etapa 7 — telas do solicitante (CHAT-01 a 03) e do técnico (CHAT-04/05): o que o servidor entrega a cada perfil. */
beforeEach(function () {
    config(['chat.enabled' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->department = Department::factory()->create(['name' => 'Financeiro']);
    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza', 'department_id' => $this->department->id]);
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    ChatAgent::forceCreate(['user_id' => $this->tech->id, 'is_available' => true, 'max_sessions' => 3, 'last_seen_at' => now()]);
});

/* ---------- Solicitante ---------- */

test('the requester sees the request screen when there is no open session', function () {
    $this->actingAs($this->requester)->get('/chat')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Chat/Index')
            ->where('session', null)
            ->where('chat', ['pollInterval' => 5, 'heartbeatInterval' => 30, 'messageMaxLength' => 2000, 'subjectMaxLength' => 255]));
});

test('the requester gets the open session, with only the own position', function () {
    $other = User::factory()->withRole('requester')->create(['department_id' => $this->department->id]);
    ChatSession::factory()->create(['requester_id' => $other->id, 'subject' => 'Assunto do outro']);
    $mine = ChatSession::factory()->create(['requester_id' => $this->requester->id, 'subject' => 'Meu assunto']);

    $this->actingAs($this->requester)->get('/chat')
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.id', $mine->id)
            ->where('session.queue', ['position' => 2, 'ahead' => 1])
            ->where('session.requester', null)
            ->etc());

    // Nada do outro solicitante vai para o navegador.
    expect(json_encode($this->actingAs($this->requester)->get('/chat')->viewData('page')['props']))
        ->not->toContain('Assunto do outro');
});

test('the floating button asks for the open session from any page', function () {
    $this->actingAs($this->requester)->getJson('/chat/current')->assertOk()->assertExactJson(['session' => null]);

    $mine = ChatSession::factory()->create(['requester_id' => $this->requester->id, 'subject' => 'Meu assunto']);

    $this->actingAs($this->requester)->getJson('/chat/current')
        ->assertOk()
        ->assertJsonPath('session.id', $mine->id)
        ->assertJsonPath('session.queue.position', 1);

    $this->actingAs($this->tech)->getJson('/chat/current')->assertForbidden();
});

test('a closed session does not come back to the request screen', function () {
    ChatSession::factory()->ended($this->tech)->create(['requester_id' => $this->requester->id]);
    ChatSession::factory()->cancelled()->create(['requester_id' => $this->requester->id]);

    $this->actingAs($this->requester)->get('/chat')->assertInertia(fn (Assert $page) => $page->where('session', null));
});

test('staff without chat.request do not get the requester screen', function (string $role) {
    $this->actingAs(User::factory()->withRole($role)->create())->get('/chat')->assertForbidden();
})->with(['technician', 'manager', 'admin']);

test('a session waiting with nobody on duty warns the requester without leaving the queue', function () {
    $session = ChatSession::factory()->create(['requester_id' => $this->requester->id]);

    $this->actingAs($this->requester)->get('/chat')->assertInertia(fn (Assert $page) => $page->where('session.no_technician_on_duty', false)->etc());

    ChatAgent::query()->update(['is_available' => false]);

    $this->actingAs($this->requester)->getJson("/chat/sessions/{$session->id}")
        ->assertJsonPath('no_technician_on_duty', true)
        ->assertJsonPath('queue.position', 1);
});

test('guests are asked to log in and a disabled module hides the screens', function () {
    $this->get('/chat')->assertRedirect('/login');
    $this->get('/chat/console')->assertRedirect('/login');

    config(['chat.enabled' => false]);
    $this->actingAs($this->requester)->get('/chat')->assertNotFound();
    $this->actingAs($this->tech)->get('/chat/console')->assertNotFound();
});

/* ---------- Técnico e gestor ---------- */

test('the technician console carries availability, the FIFO queue and the active sessions with unread counts', function () {
    $session = ChatSession::factory()->active($this->tech)->create(['requester_id' => $this->requester->id, 'subject' => 'Impressora']);
    foreach (['a', 'b', 'c'] as $text) {
        ChatMessage::forceCreate(['chat_session_id' => $session->id, 'user_id' => $this->requester->id, 'body' => $text, 'client_id' => (string) Str::uuid(), 'created_at' => now()]);
    }
    ChatMessage::forceCreate(['chat_session_id' => $session->id, 'user_id' => $this->tech->id, 'body' => 'minha', 'client_id' => (string) Str::uuid(), 'created_at' => now()]);
    $session->forceFill(['technician_last_read_id' => ChatMessage::orderBy('id')->first()->id])->save();
    $waiting = User::factory()->withRole('requester')->create(['department_id' => $this->department->id]);
    ChatSession::factory()->create(['requester_id' => $waiting->id, 'department_id' => $this->department->id, 'subject' => 'Na fila']);

    $this->actingAs($this->tech)->get('/chat/console')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Chat/Console')
            ->where('console.agent.availability.value', 'available')
            ->where('console.agent.active_sessions', 1)
            ->where('console.agent.max_sessions', 3)
            ->where('console.queue.waiting', 1)
            ->where('console.queue.entries.0.subject', 'Na fila')
            ->where('console.queue.entries.0.department', 'Financeiro')
            ->has('console.sessions', 1)
            ->where('console.sessions.0.id', $session->id)
            ->where('console.sessions.0.requester', ['name' => 'Ana Souza'])
            // Duas das três mensagens do solicitante ainda não foram lidas; a do próprio técnico não conta.
            ->where('console.sessions.0.unread', 2));
});

test('the console state is also served as JSON for live refresh', function () {
    $this->actingAs($this->tech)->getJson('/chat/console/state')
        ->assertOk()
        ->assertJsonStructure(['agent' => ['is_available', 'availability', 'active_sessions', 'max_sessions'], 'queue' => ['waiting', 'entries'], 'sessions']);
});

test('the manager console shows the queue only, without availability or sessions', function () {
    ChatSession::factory()->create(['requester_id' => $this->requester->id]);
    $manager = User::factory()->withRole('manager')->create();

    $this->actingAs($manager)->get('/chat/console')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('console.agent', null)
            ->where('console.queue.waiting', 1)
            ->where('console.sessions', []));
});

test('requesters and admins do not get the console', function (string $role) {
    $user = $role === 'requester' ? $this->requester : User::factory()->withRole($role)->create();

    $this->actingAs($user)->get('/chat/console')->assertForbidden();
    $this->actingAs($user)->getJson('/chat/console/state')->assertForbidden();
})->with(['requester', 'admin']);

test('a technician only sees their own sessions in the console', function () {
    $other = User::factory()->withRole('technician')->create();
    ChatSession::factory()->active($other)->create(['subject' => 'De outro técnico']);

    $this->actingAs($this->tech)->getJson('/chat/console/state')->assertJsonPath('sessions', []);
});

/* ---------- Menu e parâmetros compartilhados ---------- */

test('chat permissions reach the screen only while the module is enabled', function () {
    $visible = fn (User $user) => $this->actingAs($user)->get('/profile')->viewData('page')['props']['auth']['permissions'];

    expect($visible($this->requester))->toContain('chat.request')
        ->and($visible($this->tech))->toContain('chat.view_queue');

    config(['chat.enabled' => false]);

    expect(collect($visible($this->requester))->filter(fn ($slug) => str_starts_with($slug, 'chat.')))->toBeEmpty()
        ->and(collect($visible($this->tech))->filter(fn ($slug) => str_starts_with($slug, 'chat.')))->toBeEmpty()
        ->and($this->actingAs($this->tech)->get('/profile')->viewData('page')['props']['chat'])->toBeNull();
});

test('the sound preference is shared with the screen', function () {
    $this->requester->forceFill(['chat_sound_enabled' => false])->save();

    $this->actingAs($this->requester)->get('/chat')
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.chat_sound_enabled', false)->etc());
});
