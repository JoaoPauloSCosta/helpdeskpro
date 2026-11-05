<?php

use App\Enums\ChatEvent;
use App\Events\Chat\ChatSessionChanged;
use App\Models\ChatAgent;
use App\Models\ChatSession;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;

/** Transferência de atendimento (etapa extra, RF-35, D-C4): passa direto, só para técnico de plantão com vaga. */
beforeEach(function () {
    config(['chat.enabled' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $department = Department::factory()->create();
    $this->requester = User::factory()->withRole('requester')->create(['department_id' => $department->id]);
    $this->from = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->to = User::factory()->withRole('technician')->create(['name' => 'Bia Alves']);
    $this->manager = User::factory()->withRole('manager')->create();
    foreach ([$this->from, $this->to] as $tech) {
        ChatAgent::forceCreate(['user_id' => $tech->id, 'is_available' => true, 'max_sessions' => 2, 'last_seen_at' => now()]);
    }
    $this->session = ChatSession::factory()->active($this->from)->create(['requester_id' => $this->requester->id]);
});

function transfer(User $actor, ChatSession $session, ?int $to)
{
    return test()->actingAs($actor)->postJson("/chat/sessions/{$session->id}/transfer", ['technician_id' => $to]);
}

test('the responsible technician transfers and the session changes hands with an event', function () {
    Event::fake([ChatSessionChanged::class]);

    transfer($this->from, $this->session, $this->to->id)->assertOk()->assertJsonPath('technician.name', 'Bia Alves');

    expect($this->session->fresh()->technician_id)->toBe($this->to->id)
        ->and($this->session->events()->where('event_type', ChatEvent::Transferred)->count())->toBe(1);
    Event::assertDispatched(ChatSessionChanged::class, fn ($e) => $e->type === ChatEvent::Transferred
        && collect([$this->requester->id, $this->from->id, $this->to->id])->diff($e->recipientIds)->isEmpty());
});

test('the manager transfers any session', function () {
    transfer($this->manager, $this->session, $this->to->id)->assertOk();

    expect($this->session->fresh()->technician_id)->toBe($this->to->id);
});

test('the previous technician loses access and the new one sends messages', function () {
    transfer($this->from, $this->session, $this->to->id)->assertOk();

    $this->actingAs($this->from)->getJson("/chat/sessions/{$this->session->id}")->assertNotFound();
    $this->actingAs($this->to)->postJson("/chat/sessions/{$this->session->id}/messages", ['body' => 'oi', 'client_id' => (string) Str::uuid()])->assertCreated();
});

test('the target must be on duty, with room, and different from the current technician', function (Closure $setup, int $status) {
    $setup($this);
    transfer($this->from, $this->session, $this->to->id)->assertStatus($status);
    expect($this->session->fresh()->technician_id)->toBe($this->from->id);
})->with([
    'switched off' => [fn ($t) => ChatAgent::where('user_id', $t->to->id)->update(['is_available' => false]), 409],
    'not present' => [fn ($t) => ChatAgent::where('user_id', $t->to->id)->update(['last_seen_at' => now()->subHour()]), 409],
    'at the limit' => [fn ($t) => ChatSession::factory()->active($t->to)->count(2)->create(), 409],
]);

test('a transfer to the same technician or to a user who attends no chat is refused', function () {
    transfer($this->from, $this->session, $this->from->id)->assertStatus(409);
    transfer($this->from, $this->session, $this->requester->id)->assertStatus(409);
    transfer($this->from, $this->session, 999999)->assertStatus(409);
});

test('only who may transfer can, and only active sessions', function () {
    $other = User::factory()->withRole('technician')->create();
    transfer($other, $this->session, $this->to->id)->assertNotFound();
    transfer($this->requester, $this->session, $this->to->id)->assertForbidden();

    $this->session->forceFill(['status' => 'ended'])->save();
    transfer($this->from, $this->session, $this->to->id)->assertStatus(409);
});

test('the targets list shows only technicians with room, with their load', function () {
    $full = User::factory()->withRole('technician')->create();
    ChatAgent::forceCreate(['user_id' => $full->id, 'is_available' => true, 'max_sessions' => 1, 'last_seen_at' => now()]);
    ChatSession::factory()->active($full)->create();

    $this->actingAs($this->from)->getJson("/chat/sessions/{$this->session->id}/transfer-targets")
        ->assertOk()
        ->assertExactJson(['targets' => [['id' => $this->to->id, 'name' => 'Bia Alves', 'active_sessions' => 0, 'max_sessions' => 2]]]);

    $this->actingAs($this->requester)->getJson("/chat/sessions/{$this->session->id}/transfer-targets")->assertForbidden();
});

test('a technician id is required', function () {
    $this->actingAs($this->from)->postJson("/chat/sessions/{$this->session->id}/transfer", [])->assertUnprocessable();
});
