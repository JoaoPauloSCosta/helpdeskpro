<?php

use App\Enums\ChatSessionStatus;
use App\Models\ChatAgent;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Department;
use App\Models\User;
use App\Support\ChatMetrics;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Etapa 9 — equipe e capacidade (CHAT-06), histórico (CHAT-07) e indicadores (CHAT-08), RF-36. */
beforeEach(function () {
    config(['chat.enabled' => true]);
    Carbon::setTestNow('2026-11-04 12:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->department = Department::factory()->create();
    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza', 'department_id' => $this->department->id]);
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create(['name' => 'Gestora']);
    $this->admin = User::factory()->withRole('admin')->create();
});

/* ---------- CHAT-06: equipe e capacidade ---------- */

test('the manager sees the technicians with effective availability and capacity', function () {
    ChatAgent::forceCreate(['user_id' => $this->tech->id, 'is_available' => true, 'max_sessions' => 1, 'last_seen_at' => now()]);
    ChatSession::factory()->active($this->tech)->create();
    User::factory()->withRole('technician')->inactive()->create();

    $this->actingAs($this->manager)->get('/chat/admin/agents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Chat/Agents')
            ->has('agents', 1)
            ->where('agents.0.name', 'Carlos Lima')
            ->where('agents.0.availability.value', 'busy')
            ->where('agents.0.active_sessions', 1)
            ->where('agents.0.max_sessions', 1)
            ->where('limits.max', 10));
});

test('a technician who never opened the panel shows the default capacity as unavailable', function () {
    $this->actingAs($this->manager)->get('/chat/admin/agents')
        ->assertInertia(fn (Assert $page) => $page->where('agents.0.availability.value', 'unavailable')->where('agents.0.max_sessions', 3)->where('agents.0.last_seen_at', null));
});

test('only who manages capacity opens the team screen', function (string $role) {
    $this->actingAs(User::factory()->withRole($role)->create())->get('/chat/admin/agents')->assertForbidden();
})->with(['requester', 'technician', 'admin']);

test('the capacity saved from the screen is audited and keeps running sessions', function () {
    ChatSession::factory()->active($this->tech)->count(2)->create();

    $this->actingAs($this->manager)->patch("/chat/admin/agents/{$this->tech->id}", ['max_sessions' => 1])->assertRedirect();

    expect(ChatAgent::firstWhere('user_id', $this->tech->id)->max_sessions)->toBe(1)
        ->and(ChatSession::where('status', ChatSessionStatus::Active)->count())->toBe(2)
        ->and($this->tech->auditLogs()->count())->toBe(1);
});

/* ---------- CHAT-07: histórico ---------- */

function historyFixture(): array
{
    $other = User::factory()->withRole('requester')->create(['name' => 'Outro', 'department_id' => test()->department->id]);
    $mine = ChatSession::factory()->ended(test()->tech)->create(['requester_id' => test()->requester->id, 'subject' => 'Meu assunto']);
    $theirs = ChatSession::factory()->ended(User::factory()->withRole('technician')->create())->create(['requester_id' => $other->id, 'subject' => 'Assunto alheio']);
    $cancelled = ChatSession::factory()->cancelled()->create(['requester_id' => $other->id]);

    return [$mine, $theirs, $cancelled];
}

test('the requester only lists and opens their own sessions', function () {
    [$mine, $theirs] = historyFixture();

    $this->actingAs($this->requester)->get('/chat/history')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Chat/History')->has('sessions', 1)->where('sessions.0.id', $mine->id)->where('canSearch', false));

    $this->actingAs($this->requester)->get("/chat/history/{$mine->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Chat/HistoryShow')->where('session.id', $mine->id));
    $this->actingAs($this->requester)->get("/chat/history/{$theirs->id}")->assertNotFound();
});

test('the technician lists the sessions they handled', function () {
    [$mine] = historyFixture();

    $this->actingAs($this->tech)->get('/chat/history')->assertInertia(fn (Assert $page) => $page->has('sessions', 1)->where('sessions.0.id', $mine->id));
});

test('the manager and the admin list every session and can search and filter', function (string $role) {
    historyFixture();
    $user = $role === 'manager' ? $this->manager : $this->admin;

    $this->actingAs($user)->get('/chat/history')->assertInertia(fn (Assert $page) => $page->has('sessions', 3)->where('canSearch', true));
    $this->actingAs($user)->get('/chat/history?status=cancelled')->assertInertia(fn (Assert $page) => $page->has('sessions', 1));
    $this->actingAs($user)->get('/chat/history?q=alheio')->assertInertia(fn (Assert $page) => $page->has('sessions', 1)->where('sessions.0.subject', 'Assunto alheio'));
    $this->actingAs($user)->get('/chat/history?q=Ana')->assertInertia(fn (Assert $page) => $page->has('sessions', 1)->where('sessions.0.subject', 'Meu assunto'));
})->with(['manager', 'admin']);

test('a search term is ignored for people who only see their own sessions', function () {
    historyFixture();

    $this->actingAs($this->requester)->get('/chat/history?q=alheio')->assertInertia(fn (Assert $page) => $page->has('sessions', 1)->where('filters.q', ''));
});

test('the history detail carries the timeline and the conversation is readable by the manager', function () {
    [$mine] = historyFixture();
    ChatMessage::forceCreate(['chat_session_id' => $mine->id, 'user_id' => $this->requester->id, 'body' => 'Olá', 'client_id' => (string) Str::uuid(), 'created_at' => now()]);

    $this->actingAs($this->manager)->get("/chat/history/{$mine->id}")
        ->assertInertia(fn (Assert $page) => $page->where('session.requester.name', 'Ana Souza')->has('session.events'));
    $this->actingAs($this->manager)->getJson("/chat/sessions/{$mine->id}/messages")->assertOk()->assertJsonPath('messages.0.body', 'Olá');
});

test('guests and a disabled module cannot reach the screens', function () {
    $this->get('/chat/history')->assertRedirect('/login');
    $this->get('/chat/metrics')->assertRedirect('/login');

    config(['chat.enabled' => false]);
    $this->actingAs($this->manager)->get('/chat/history')->assertNotFound();
    $this->actingAs($this->manager)->get('/chat/metrics')->assertNotFound();
});

/* ---------- CHAT-08: indicadores ---------- */

test('the metrics follow the documented formulas', function () {
    $tech = $this->tech;
    ChatAgent::forceCreate(['user_id' => $tech->id, 'is_available' => true, 'max_sessions' => 2, 'last_seen_at' => now()]);
    $ts = fn (string $time) => "2026-11-03 {$time}";

    // Resolvida: espera 5 min, primeira resposta 2 min, duração 30 min.
    ChatSession::factory()->ended($tech)->create(['requester_id' => $this->requester->id, 'outcome' => 'resolved', 'created_at' => $ts('10:00:00'), 'accepted_at' => $ts('10:05:00'), 'first_response_at' => $ts('10:07:00'), 'ended_at' => $ts('10:35:00')]);
    // Não resolvida: espera 15 min, sem mensagem do técnico, duração 10 min.
    ChatSession::factory()->ended($tech)->create(['outcome' => 'unresolved', 'created_at' => $ts('11:00:00'), 'accepted_at' => $ts('11:15:00'), 'ended_at' => $ts('11:25:00')]);
    ChatSession::factory()->cancelled()->create(['created_at' => $ts('12:00:00')]);
    ChatSession::factory()->active($tech)->create(['created_at' => $ts('13:00:00'), 'accepted_at' => $ts('13:10:00'), 'first_response_at' => $ts('13:14:00')]);
    ChatSession::factory()->create(['created_at' => $ts('14:00:00')]);
    // Fora do período de 7 dias.
    ChatSession::factory()->ended($tech)->create(['outcome' => 'resolved', 'created_at' => '2026-10-01 10:00:00', 'accepted_at' => '2026-10-01 10:01:00', 'ended_at' => '2026-10-01 10:02:00']);

    $metrics = (new ChatMetrics(7, now()))->build();

    expect($metrics)
        ->requested->toBe(5)
        ->ended->toBe(2)
        ->cancelled->toBe(1)
        ->resolved->toBe(1)
        ->unresolved->toBe(1)
        ->resolved_rate->toBe(50.0)
        ->cancelled_rate->toBe(20.0)
        // Atendidas: 5, 15 e 10 min de espera.
        ->avg_wait_seconds->toBe(600)
        // Só quem teve resposta: 2 e 4 min.
        ->avg_first_response_seconds->toBe(180)
        ->avg_duration_seconds->toBe(1200)
        ->now->toBe(['waiting' => 1, 'active' => 1]);

    expect($metrics['technicians'])->toHaveCount(1)
        ->and($metrics['technicians'][0])->toMatchArray(['name' => 'Carlos Lima', 'attended' => 3, 'ended' => 2, 'resolved_rate' => 50.0, 'active_now' => 1, 'max_sessions' => 2]);
});

test('without data the times are null, never zero', function () {
    $metrics = (new ChatMetrics(30, now()))->build();

    expect($metrics)->requested->toBe(0)->resolved_rate->toBeNull()->cancelled_rate->toBeNull()
        ->avg_wait_seconds->toBeNull()->avg_first_response_seconds->toBeNull()->avg_duration_seconds->toBeNull()
        ->and($metrics['technicians'])->toBeEmpty();
});

test('the manager and the admin open the metrics and an unknown period falls back to 30 days', function (string $role) {
    $user = $role === 'manager' ? $this->manager : $this->admin;

    $this->actingAs($user)->get('/chat/metrics?days=7')->assertInertia(fn (Assert $page) => $page->component('Chat/Metrics')->where('metrics.days', 7)->where('periods', [7, 30, 90]));
    $this->actingAs($user)->get('/chat/metrics?days=999')->assertInertia(fn (Assert $page) => $page->where('metrics.days', 30));
})->with(['manager', 'admin']);

test('requesters and technicians cannot open the metrics', function (string $role) {
    $this->actingAs(User::factory()->withRole($role)->create())->get('/chat/metrics')->assertForbidden();
})->with(['requester', 'technician']);
