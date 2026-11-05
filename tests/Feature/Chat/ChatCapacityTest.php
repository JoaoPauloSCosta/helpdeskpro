<?php

use App\Enums\AdminEvent;
use App\Enums\ChatAvailability;
use App\Enums\ChatSessionStatus;
use App\Models\AuditLog;
use App\Models\ChatAgent;
use App\Models\ChatSession;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/** Etapa 4 — capacidade por técnico (RF-28, RF-36, RN-CHAT-04, D-C7). A tela CHAT-06 vem na Etapa 9. */
beforeEach(function () {
    config(['chat.enabled' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = User::factory()->withRole('manager')->create();
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
});

function patchCapacity(User $operator, int|string $technician, mixed $max)
{
    return test()->actingAs($operator)->from('/dashboard')->patch("/chat/admin/agents/{$technician}", ['max_sessions' => $max]);
}

function capacityOf(User $tech): ?int
{
    return ChatAgent::firstWhere('user_id', $tech->id)?->max_sessions;
}

test('the manager changes a technician capacity and the change is audited', function () {
    patchCapacity($this->manager, $this->tech->id, 5)
        ->assertRedirect('/dashboard')
        ->assertSessionHas('success', 'Capacidade de Carlos Lima no chat alterada para 5 atendimentos simultâneos.');

    $log = AuditLog::sole();

    expect(capacityOf($this->tech))->toBe(5)
        ->and($log->event_type)->toBe(AdminEvent::ChatCapacityUpdated)
        ->and($log->user_id)->toBe($this->manager->id)
        ->and($log->auditable->is($this->tech))->toBeTrue()
        ->and($log->old_values)->toBe(['chat_max_sessions' => config('chat.default_capacity')])
        ->and($log->new_values)->toBe(['chat_max_sessions' => 5]);
});

test('saving the same capacity changes and records nothing', function () {
    patchCapacity($this->manager, $this->tech->id, config('chat.default_capacity'))
        ->assertSessionHas('info', 'Nenhuma alteração para salvar.');

    expect(AuditLog::count())->toBe(0);
});

test('the capacity must be between 1 and the configured maximum', function (mixed $value) {
    patchCapacity($this->manager, $this->tech->id, $value)
        ->assertSessionHasErrors(['max_sessions' => 'Informe um limite entre 1 e 10 atendimentos simultâneos.']);

    expect(capacityOf($this->tech))->toBeNull()
        ->and(AuditLog::count())->toBe(0);
})->with([0, 11, 'três', null, 2.5]);

test('only chat.manage_capacity changes capacity, even for unknown identifiers', function (string $role) {
    $operator = User::factory()->withRole($role)->create();

    patchCapacity($operator, $this->tech->id, 5)->assertForbidden();
    patchCapacity($operator, 999999, 5)->assertForbidden();

    expect(capacityOf($this->tech))->toBeNull()
        ->and(AuditLog::count())->toBe(0);
})->with(['technician', 'admin', 'requester']);

test('an inactive manager cannot change capacity', function () {
    $this->manager->forceFill(['is_active' => false])->save();

    patchCapacity($this->manager, $this->tech->id, 5)->assertForbidden();
});

test('only users who attend the chat have a capacity', function () {
    $requester = User::factory()->withRole('requester')->create();

    patchCapacity($this->manager, $requester->id, 5)->assertNotFound();
    patchCapacity($this->manager, 999999, 5)->assertNotFound();

    expect(ChatAgent::count())->toBe(0);
});

test('lowering the capacity below the active sessions keeps them and makes the technician busy', function () {
    ChatAgent::forceCreate(['user_id' => $this->tech->id, 'is_available' => true, 'max_sessions' => 3, 'last_seen_at' => now()]);
    ChatSession::factory()->count(3)->active($this->tech)->create();

    patchCapacity($this->manager, $this->tech->id, 1)->assertSessionHasNoErrors();

    $agent = ChatAgent::firstWhere('user_id', $this->tech->id);

    expect($agent->max_sessions)->toBe(1)
        ->and($agent->availability())->toBe(ChatAvailability::Busy)
        ->and(ChatSession::where('technician_id', $this->tech->id)->where('status', ChatSessionStatus::Active)->count())->toBe(3);
});

test('the audit screen shows the capacity change', function () {
    patchCapacity($this->manager, $this->tech->id, 5);
    $admin = User::factory()->withRole('admin')->create();
    $log = AuditLog::sole();

    $this->actingAs($admin)->get("/audit?selected={$log->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('detail.event', ['value' => 'chat_capacity_updated', 'label' => 'Capacidade no chat alterada'])
            ->where('detail.changes', [['field' => 'Capacidade no chat', 'from' => '3', 'to' => '5']]));
});
