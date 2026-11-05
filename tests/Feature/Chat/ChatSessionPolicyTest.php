<?php

use App\Models\ChatSession;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/** Acesso às sessões (Etapa 3): participação e permissão, nunca só o id. */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->requester = User::factory()->withRole('requester')->create();
    $this->tech = User::factory()->withRole('technician')->create();
    $this->otherTech = User::factory()->withRole('technician')->create();
    $this->otherRequester = User::factory()->withRole('requester')->create();
    $this->manager = User::factory()->withRole('manager')->create();
    $this->admin = User::factory()->withRole('admin')->create();

    $this->waiting = ChatSession::factory()->create(['requester_id' => $this->requester->id]);
    $this->active = ChatSession::factory()->active($this->tech)->create(['requester_id' => $this->otherRequester->id]);
});

function chatAllows(User $user, string $ability, ChatSession $session): bool
{
    return Gate::forUser($user)->allows($ability, $session);
}

test('participants and history viewers see a session; others do not', function () {
    expect(chatAllows($this->otherRequester, 'view', $this->active))->toBeTrue()
        ->and(chatAllows($this->tech, 'view', $this->active))->toBeTrue()
        ->and(chatAllows($this->manager, 'view', $this->active))->toBeTrue()
        ->and(chatAllows($this->admin, 'view', $this->active))->toBeTrue()
        ->and(chatAllows($this->requester, 'view', $this->active))->toBeFalse()
        ->and(chatAllows($this->otherTech, 'view', $this->active))->toBeFalse()
        // Na fila ainda não há técnico: nenhum técnico vê a sessão por este caminho.
        ->and(chatAllows($this->tech, 'view', $this->waiting))->toBeFalse();
});

test('only the two participants send messages, and only while the session is active', function () {
    expect(chatAllows($this->otherRequester, 'sendMessage', $this->active))->toBeTrue()
        ->and(chatAllows($this->tech, 'sendMessage', $this->active))->toBeTrue()
        ->and(chatAllows($this->otherTech, 'sendMessage', $this->active))->toBeFalse()
        ->and(chatAllows($this->manager, 'sendMessage', $this->active))->toBeFalse()
        ->and(chatAllows($this->requester, 'sendMessage', $this->waiting))->toBeFalse();

    $this->waiting->forceFill(['status' => 'cancelled', 'active_requester_id' => null])->save();
    $ended = ChatSession::factory()->ended($this->tech)->create(['requester_id' => $this->requester->id]);

    expect(chatAllows($this->requester, 'sendMessage', $ended))->toBeFalse()
        ->and(chatAllows($this->tech, 'sendMessage', $ended))->toBeFalse();
});

test('only the requester cancels, and only while waiting', function () {
    expect(chatAllows($this->requester, 'cancel', $this->waiting))->toBeTrue()
        ->and(chatAllows($this->otherRequester, 'cancel', $this->waiting))->toBeFalse()
        ->and(chatAllows($this->tech, 'cancel', $this->waiting))->toBeFalse()
        ->and(chatAllows($this->manager, 'cancel', $this->waiting))->toBeFalse()
        ->and(chatAllows($this->otherRequester, 'cancel', $this->active))->toBeFalse();
});

test('the responsible technician and the manager transfer; nobody else does', function () {
    expect(chatAllows($this->tech, 'transfer', $this->active))->toBeTrue()
        ->and(chatAllows($this->manager, 'transfer', $this->active))->toBeTrue()
        ->and(chatAllows($this->otherTech, 'transfer', $this->active))->toBeFalse()
        ->and(chatAllows($this->admin, 'transfer', $this->active))->toBeFalse()
        ->and(chatAllows($this->otherRequester, 'transfer', $this->active))->toBeFalse()
        ->and(chatAllows($this->manager, 'transfer', $this->waiting))->toBeFalse();
});

test('only the responsible technician closes an active session', function () {
    expect(chatAllows($this->tech, 'close', $this->active))->toBeTrue()
        ->and(chatAllows($this->otherTech, 'close', $this->active))->toBeFalse()
        ->and(chatAllows($this->manager, 'close', $this->active))->toBeFalse()
        ->and(chatAllows($this->otherRequester, 'close', $this->active))->toBeFalse();
});

test('an inactive participant can do nothing', function () {
    $this->tech->forceFill(['is_active' => false])->save();
    $this->otherRequester->forceFill(['is_active' => false])->save();

    foreach (['view', 'sendMessage', 'transfer', 'close'] as $ability) {
        expect(chatAllows($this->tech, $ability, $this->active))->toBeFalse()
            ->and(chatAllows($this->otherRequester, $ability, $this->active))->toBeFalse();
    }
});

test('a technician whose permissions were revoked can no longer act on the session', function () {
    $role = $this->tech->role;
    $role->permissions()->detach($role->permissions()->whereIn('slug', ['chat.close', 'chat.send_message', 'chat.transfer'])->pluck('permissions.id'));
    $tech = $this->tech->fresh();

    expect(chatAllows($tech, 'close', $this->active))->toBeFalse()
        ->and(chatAllows($tech, 'sendMessage', $this->active))->toBeFalse()
        ->and(chatAllows($tech, 'transfer', $this->active))->toBeFalse()
        // Continua vendo a sessão que atende, para um gestor poder transferi-la.
        ->and(chatAllows($tech, 'view', $this->active))->toBeTrue();
});
