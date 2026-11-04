<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Matriz aprovada (docs/chat/01-decisoes-e-contratos.md, seção 7). */
const CHAT_MATRIX = [
    'requester' => ['chat.request', 'chat.send_message'],
    'technician' => ['chat.accept_next', 'chat.close', 'chat.manage_availability', 'chat.send_message', 'chat.transfer', 'chat.view_queue'],
    'manager' => ['chat.manage_capacity', 'chat.transfer', 'chat.view_history', 'chat.view_metrics', 'chat.view_queue'],
    'admin' => ['chat.view_history', 'chat.view_metrics'],
];

const CHAT_MIGRATION = 'migrations/2026_11_04_000005_add_chat_permissions.php';

const CHAT_GATES = [
    'requestChat' => 'chat.request',
    'viewChatQueue' => 'chat.view_queue',
    'acceptNextChat' => 'chat.accept_next',
    'manageChatAvailability' => 'chat.manage_availability',
    'manageChatCapacity' => 'chat.manage_capacity',
    'viewChatHistory' => 'chat.view_history',
    'viewChatMetrics' => 'chat.view_metrics',
];

function chatPermissionsOf(string $role): array
{
    return Role::where('slug', $role)->first()->permissions()
        ->where('slug', 'like', 'chat.%')->orderBy('slug')->pluck('slug')->all();
}

test('the seeder creates the ten chat permissions and the approved matrix', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::where('slug', 'like', 'chat.%')->count())->toBe(10);

    foreach (CHAT_MATRIX as $role => $expected) {
        expect(chatPermissionsOf($role))->toBe($expected);
    }
});

test('chat permissions are grouped under the Chat module', function () {
    expect((new Permission(['slug' => 'chat.request']))->group())->toBe('chat')
        ->and(Permission::groupLabels()['chat'])->toBe('Chat');
});

test('only history and metrics are administrative, so the administrator can still grant every system role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole('admin')->create();

    expect(array_values(array_filter(Permission::ADMINISTRATIVE, fn ($slug) => str_starts_with($slug, 'chat.'))))
        ->toBe(['chat.view_history', 'chat.view_metrics']);

    foreach (['requester', 'technician', 'manager', 'admin'] as $role) {
        expect(Role::where('slug', $role)->with('permissions')->first()->isGrantableBy($admin))->toBeTrue();
    }
});

test('the migration grants the matrix to existing system roles once and leaves custom roles alone', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Banco como antes do chat: sem as permissões (as associações caem junto, em cascata).
    DB::table('permissions')->where('slug', 'like', 'chat.%')->delete();
    $custom = Role::forceCreate(['name' => 'Suporte N2', 'slug' => 'support-n2', 'is_system' => false]);
    $grantsBefore = DB::table('role_permissions')->count();

    $migration = require database_path(CHAT_MIGRATION);
    $migration->up();
    $migration->up();

    expect(Permission::where('slug', 'like', 'chat.%')->count())->toBe(10)
        ->and(DB::table('role_permissions')->count())->toBe($grantsBefore + array_sum(array_map('count', CHAT_MATRIX)))
        ->and($custom->permissions()->count())->toBe(0);

    foreach (CHAT_MATRIX as $role => $expected) {
        expect(chatPermissionsOf($role))->toBe($expected);
    }
});

test('the migration only creates the catalog when there are no roles', function () {
    $migration = require database_path(CHAT_MIGRATION);
    $migration->up();

    expect(Permission::where('slug', 'like', 'chat.%')->count())->toBe(10)
        ->and(DB::table('role_permissions')->count())->toBe(0);
});

test('rolling back removes only the chat permissions and their grants', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $before = DB::table('role_permissions')->count();

    (require database_path(CHAT_MIGRATION))->down();

    expect(Permission::where('slug', 'like', 'chat.%')->count())->toBe(0)
        ->and(Permission::count())->toBe(20)
        ->and(DB::table('role_permissions')->count())->toBe($before - array_sum(array_map('count', CHAT_MATRIX)));
});

test('chat gates follow the matrix for each role', function (string $role) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->withRole($role)->create();

    foreach (CHAT_GATES as $ability => $permission) {
        expect(Gate::forUser($user)->allows($ability))->toBe(in_array($permission, CHAT_MATRIX[$role], true), $ability);
    }
})->with(array_keys(CHAT_MATRIX));

test('inactive users and users without a role pass no chat gate', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $inactive = User::factory()->withRole('manager')->inactive()->create();
    $roleless = User::factory()->create();

    foreach (array_keys(CHAT_GATES) as $ability) {
        expect(Gate::forUser($inactive)->allows($ability))->toBeFalse()
            ->and(Gate::forUser($roleless)->allows($ability))->toBeFalse();
    }
});
