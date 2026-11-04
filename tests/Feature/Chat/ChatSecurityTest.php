<?php

use App\Models\ChatSession;
use App\Models\Department;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/** Etapa 10 — limites de taxa e acesso direto por identificador, em todas as rotas de sessão. */
beforeEach(function () {
    config(['chat.enabled' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $department = Department::factory()->create();
    $this->requester = User::factory()->withRole('requester')->create(['department_id' => $department->id]);
    $this->tech = User::factory()->withRole('technician')->create();
});

test('messages are rate limited per user', function () {
    $session = ChatSession::factory()->active($this->tech)->create(['requester_id' => $this->requester->id]);
    $send = fn () => $this->actingAs($this->requester)->postJson("/chat/sessions/{$session->id}/messages", ['body' => 'oi', 'client_id' => (string) Str::uuid()]);

    foreach (range(1, 30) as $ignored) {
        $send()->assertCreated();
    }

    $send()->assertTooManyRequests();
});

test('requests for chat are rate limited per user', function () {
    foreach (range(1, 6) as $ignored) {
        $this->actingAs($this->requester)->postJson('/chat/sessions', ['subject' => 'Ajuda'])->assertStatus(409); // sem técnico de plantão; conta no limite do mesmo jeito
    }

    $this->actingAs($this->requester)->postJson('/chat/sessions', ['subject' => 'Ajuda'])->assertTooManyRequests();
});

test('an outsider gets 404 on every route that takes a session id', function () {
    $session = ChatSession::factory()->active($this->tech)->create(['requester_id' => $this->requester->id]);
    $outsider = User::factory()->withRole('requester')->create(['department_id' => $this->requester->department_id]);
    $otherTech = User::factory()->withRole('technician')->create();
    // Com dados válidos: a validação vem antes da busca da sessão e responde 422 igual para qualquer id.
    $closure = ['outcome' => 'unresolved', 'title' => 'Titulo', 'category_id' => TicketCategory::factory()->create()->id];

    foreach ([$outsider, $otherTech] as $user) {
        $this->actingAs($user);
        $this->getJson("/chat/sessions/{$session->id}")->assertNotFound();
        $this->getJson("/chat/sessions/{$session->id}/messages")->assertNotFound();
        $this->postJson("/chat/sessions/{$session->id}/messages", ['body' => 'x', 'client_id' => (string) Str::uuid()])->assertNotFound();
        $this->postJson("/chat/sessions/{$session->id}/read")->assertNotFound();
        $this->postJson("/chat/sessions/{$session->id}/cancellation")->assertNotFound();
        $this->postJson("/chat/sessions/{$session->id}/closure", $closure)->assertNotFound();
    }
});

test('an outsider cannot open the history of another session', function () {
    $session = ChatSession::factory()->ended($this->tech)->create(['requester_id' => $this->requester->id]);
    $outsider = User::factory()->withRole('requester')->create(['department_id' => $this->requester->department_id]);

    $this->actingAs($outsider)->get("/chat/history/{$session->id}")->assertNotFound();
});
