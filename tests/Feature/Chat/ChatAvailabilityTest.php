<?php

use App\Actions\Chat\ChatAgentUnavailable;
use App\Actions\Chat\ChatCapacityReached;
use App\Actions\Chat\LockAgentForAssignment;
use App\Enums\ChatAvailability;
use App\Enums\ChatSessionStatus;
use App\Models\ChatAgent;
use App\Models\ChatSession;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/** Etapa 4 — disponibilidade manual, presença e disponibilidade efetiva (RF-28, RN-CHAT-03, D-C2, D-C3). */
beforeEach(function () {
    config(['chat.enabled' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->tech = User::factory()->withRole('technician')->create();
});

/** Técnico disponível e presente, com a capacidade informada. */
function onDutyAgent(User $tech, int $max = 3, array $attributes = []): ChatAgent
{
    return ChatAgent::forceCreate([
        'user_id' => $tech->id,
        'is_available' => true,
        'max_sessions' => $max,
        'last_seen_at' => now(),
        ...$attributes,
    ]);
}

function activeSessionsFor(User $tech, int $count): void
{
    ChatSession::factory()->count($count)->active($tech)->create();
}

function availabilityOf(User $tech): ChatAvailability
{
    return ChatAgent::firstWhere('user_id', $tech->id)->availability();
}

/* ---------- Acesso ---------- */

test('with the module disabled the routes do not exist for logged-in users', function () {
    config(['chat.enabled' => false]);

    // Visitantes vão ao login antes, como em qualquer rota protegida; nada do chat é exposto.
    $this->getJson('/chat/agent')->assertUnauthorized();
    $this->actingAs($this->tech)->getJson('/chat/agent')->assertNotFound();
    $this->actingAs($this->tech)->putJson('/chat/agent/availability', ['available' => true])->assertNotFound();
    $this->actingAs($this->tech)->postJson('/chat/agent/heartbeat')->assertNotFound();

    expect(ChatAgent::count())->toBe(0);
});

test('guests are asked to log in', function () {
    $this->get('/chat/agent')->assertRedirect('/login');
    $this->put('/chat/agent/availability', ['available' => true])->assertRedirect('/login');
});

test('users without chat.manage_availability cannot read or change availability', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->getJson('/chat/agent')->assertForbidden();
    $this->actingAs($user)->putJson('/chat/agent/availability', ['available' => true])->assertForbidden();
    $this->actingAs($user)->postJson('/chat/agent/heartbeat')->assertForbidden();

    expect(ChatAgent::count())->toBe(0);
})->with(['requester', 'manager', 'admin']);

test('an inactive technician cannot change availability', function () {
    $this->tech->forceFill(['is_active' => false])->save();

    $this->actingAs($this->tech)->putJson('/chat/agent/availability', ['available' => true])->assertForbidden();

    expect(ChatAgent::count())->toBe(0);
});

/* ---------- Disponibilidade manual ---------- */

test('a technician starts unavailable with the default capacity', function () {
    $this->actingAs($this->tech)->getJson('/chat/agent')
        ->assertOk()
        ->assertExactJson([
            'is_available' => false,
            'availability' => ['value' => 'unavailable', 'label' => 'Indisponível'],
            'active_sessions' => 0,
            'max_sessions' => config('chat.default_capacity'),
        ]);
});

test('turning availability on counts as presence and makes the technician available', function () {
    $this->freezeSecond();

    $this->actingAs($this->tech)->putJson('/chat/agent/availability', ['available' => true])
        ->assertOk()
        ->assertJsonPath('is_available', true)
        ->assertJsonPath('availability.value', 'available');

    expect(ChatAgent::firstWhere('user_id', $this->tech->id)->last_seen_at->equalTo(now()))->toBeTrue();
});

test('turning availability off makes the technician unavailable', function () {
    onDutyAgent($this->tech);

    $this->actingAs($this->tech)->putJson('/chat/agent/availability', ['available' => false])
        ->assertOk()
        ->assertJsonPath('is_available', false)
        ->assertJsonPath('availability.value', 'unavailable');
});

test('availability must be a boolean', function (mixed $value) {
    $this->actingAs($this->tech)->putJson('/chat/agent/availability', ['available' => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['available' => 'Informe se você está disponível.']);
})->with([null, 'talvez', 3]);

test('the request only changes the authenticated technician', function () {
    $other = User::factory()->withRole('technician')->create();
    onDutyAgent($other);

    $this->actingAs($this->tech)->putJson('/chat/agent/availability', ['available' => false, 'user_id' => $other->id])->assertOk();

    expect(ChatAgent::firstWhere('user_id', $other->id)->is_available)->toBeTrue();
});

/* ---------- Presença ---------- */

test('the heartbeat records presence', function () {
    onDutyAgent($this->tech, attributes: ['last_seen_at' => now()->subHour()]);
    $this->freezeSecond();

    $this->actingAs($this->tech)->postJson('/chat/agent/heartbeat')->assertNoContent();

    expect(ChatAgent::firstWhere('user_id', $this->tech->id)->last_seen_at->equalTo(now()))->toBeTrue();
});

test('without a heartbeat within the presence window the technician becomes unavailable', function () {
    onDutyAgent($this->tech);

    $this->travel(config('chat.presence_ttl') - 1)->seconds();
    expect(availabilityOf($this->tech))->toBe(ChatAvailability::Available);

    $this->travel(2)->seconds();
    expect(availabilityOf($this->tech))->toBe(ChatAvailability::Unavailable);

    $this->actingAs($this->tech)->postJson('/chat/agent/heartbeat')->assertNoContent();
    expect(availabilityOf($this->tech))->toBe(ChatAvailability::Available);
});

test('logging out turns availability off and keeps active sessions', function () {
    onDutyAgent($this->tech);
    activeSessionsFor($this->tech, 1);

    $this->actingAs($this->tech)->post('/logout');

    expect(ChatAgent::firstWhere('user_id', $this->tech->id)->is_available)->toBeFalse()
        ->and(ChatSession::where('technician_id', $this->tech->id)->where('status', ChatSessionStatus::Active)->count())->toBe(1);
});

/* ---------- Capacidade e disponibilidade efetiva ---------- */

test('a technician at the limit is busy and becomes available again below it', function () {
    onDutyAgent($this->tech, max: 2);
    activeSessionsFor($this->tech, 2);

    $this->actingAs($this->tech)->getJson('/chat/agent')
        ->assertJsonPath('availability', ['value' => 'busy', 'label' => 'Ocupado'])
        ->assertJsonPath('active_sessions', 2);

    ChatSession::where('technician_id', $this->tech->id)->first()
        ->forceFill(['status' => ChatSessionStatus::Ended, 'active_requester_id' => null, 'ended_at' => now()])->save();

    expect(availabilityOf($this->tech))->toBe(ChatAvailability::Available);
});

test('only active sessions count against the capacity', function () {
    onDutyAgent($this->tech, max: 1);
    ChatSession::factory()->ended($this->tech)->create();
    ChatSession::factory()->create(); // na fila, sem técnico

    expect(availabilityOf($this->tech))->toBe(ChatAvailability::Available);
});

test('a limit below the active sessions only makes the technician busy', function () {
    onDutyAgent($this->tech, max: 3);
    activeSessionsFor($this->tech, 3);
    ChatAgent::where('user_id', $this->tech->id)->update(['max_sessions' => 1]);

    expect(availabilityOf($this->tech))->toBe(ChatAvailability::Busy)
        ->and(ChatSession::where('technician_id', $this->tech->id)->where('status', ChatSessionStatus::Active)->count())->toBe(3);
});

test('an inactive technician or one without chat.accept_next is unavailable even with the switch on', function () {
    onDutyAgent($this->tech);
    $this->tech->forceFill(['is_active' => false])->save();
    expect(availabilityOf($this->tech))->toBe(ChatAvailability::Unavailable);

    $other = User::factory()->withRole('technician')->create();
    onDutyAgent($other);
    $role = $other->role;
    $role->permissions()->detach($role->permissions()->where('slug', 'chat.accept_next')->pluck('permissions.id'));

    expect(availabilityOf($other->fresh()))->toBe(ChatAvailability::Unavailable);
});

test('on duty means active, permitted, switched on and present, even when busy', function () {
    $available = User::factory()->withRole('technician')->create();
    $busy = User::factory()->withRole('technician')->create();
    $off = User::factory()->withRole('technician')->create();
    $stale = User::factory()->withRole('technician')->create();
    $inactive = User::factory()->withRole('technician')->inactive()->create();
    $manager = User::factory()->withRole('manager')->create();

    onDutyAgent($available);
    onDutyAgent($busy, max: 1);
    activeSessionsFor($busy, 1);
    onDutyAgent($off, attributes: ['is_available' => false]);
    onDutyAgent($stale, attributes: ['last_seen_at' => now()->subSeconds(config('chat.presence_ttl') + 1)]);
    onDutyAgent($inactive);
    onDutyAgent($manager);

    expect(ChatAgent::onDuty()->orderBy('user_id')->pluck('user_id')->all())->toBe([$available->id, $busy->id]);
});

/* ---------- Guarda de capacidade ---------- */

test('the capacity guard returns the locked agent when there is room', function () {
    onDutyAgent($this->tech, max: 2);
    activeSessionsFor($this->tech, 1);

    $agent = DB::transaction(fn () => app(LockAgentForAssignment::class)($this->tech));

    expect($agent->user_id)->toBe($this->tech->id);
});

test('the capacity guard refuses a technician at the limit', function () {
    onDutyAgent($this->tech, max: 1);
    activeSessionsFor($this->tech, 1);

    DB::transaction(fn () => app(LockAgentForAssignment::class)($this->tech));
})->throws(ChatCapacityReached::class);

test('the capacity guard refuses an unavailable technician', function (array $attributes) {
    onDutyAgent($this->tech, attributes: $attributes);

    DB::transaction(fn () => app(LockAgentForAssignment::class)($this->tech));
})->with([
    'switched off' => [['is_available' => false]],
    'not present' => [['last_seen_at' => null]],
])->throws(ChatAgentUnavailable::class);

test('the capacity guard refuses a technician who never opened the panel', function () {
    DB::transaction(fn () => app(LockAgentForAssignment::class)($this->tech));
})->throws(ChatAgentUnavailable::class);
