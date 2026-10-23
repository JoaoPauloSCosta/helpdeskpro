<?php

use App\Enums\AdminEvent;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create(['name' => 'Alice Admin', 'email' => 'alice@example.com']);
});

/** Grava um evento como as Actions fazem: `created_at` e o autor vêm do backend. */
function auditEvent(object $entity, ?User $author, AdminEvent $event, ?array $old, ?array $new, string $at = '2026-10-21 12:00:00'): AuditLog
{
    return $entity->auditLogs()->forceCreate([
        'user_id' => $author?->id,
        'event_type' => $event,
        'old_values' => $old,
        'new_values' => $new,
        'created_at' => $at,
    ]);
}

function auditProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/** Papel personalizado só com a permissão informada. */
function auditOperatorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Somente {$permission}", 'slug' => str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/audit')->assertRedirect('/login');
});

test('users without audit.view get a neutral access denied page without audit data', function (string $role) {
    auditEvent($this->admin, $this->admin, AdminEvent::UserUpdated, ['name' => 'A'], ['name' => 'B']);

    $this->actingAs(User::factory()->withRole($role)->create())
        ->get('/audit')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->missing('logs')
            ->missing('detail'));
})->with(['requester', 'technician']);

test('the detail parameter does not bypass authorization', function () {
    $log = auditEvent($this->admin, $this->admin, AdminEvent::UserUpdated, ['name' => 'A'], ['name' => 'B']);

    $this->actingAs(User::factory()->withRole('technician')->create())
        ->get("/audit?selected={$log->id}")
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->missing('detail'));
});

test('other permissions do not grant access to the audit trail', function (string $permission) {
    $this->actingAs(auditOperatorWithOnly($permission))->get('/audit')->assertForbidden();
})->with(['users.manage', 'roles.manage', 'sla.manage', 'tickets.view_all_departments']);

test('access follows the permission, not the role name', function () {
    $this->actingAs(auditOperatorWithOnly('audit.view'))->get('/audit')->assertOk();

    Role::where('slug', 'admin')->first()->permissions()->detach();
    $this->actingAs($this->admin->fresh())->get('/audit')->assertForbidden();
});

test('inactive users with audit.view are denied', function () {
    $this->actingAs(User::factory()->withRole('admin')->inactive()->create())->get('/audit')->assertForbidden();
});

test('managers and administrators can open the page', function (string $role) {
    $this->actingAs(User::factory()->withRole($role)->create())
        ->get('/audit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Audit/Index')
            ->where('filters', [])
            ->where('sort', ['key' => 'created_at', 'direction' => 'desc'])
            ->where('hasAnyLogs', false)
            ->has('logs.data', 0)
            ->where('detail', null));
})->with(['manager', 'admin']);

/* ---------- Dados ---------- */

test('rows come from audit_logs, newest first, with author and entity', function () {
    $department = Department::factory()->create(['name' => 'Financeiro']);
    auditEvent($department, $this->admin, AdminEvent::DepartmentCreated, null, ['name' => 'Financeiro', 'is_active' => true], '2026-10-20 08:00:00');
    auditEvent($department, $this->admin, AdminEvent::DepartmentDeactivated, ['is_active' => true], ['is_active' => false], '2026-10-21 09:30:00');

    $rows = auditProps($this->actingAs($this->admin)->get('/audit'))['logs']['data'];

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['event'])->toBe(['value' => 'department_deactivated', 'label' => 'Departamento inativado'])
        ->and($rows[0]['author'])->toBe('Alice Admin')
        ->and($rows[0]['created_at'])->toBe('2026-10-21T09:30:00+00:00')
        ->and($rows[0]['entity'])->toBe(['type' => 'Departamento', 'id' => $department->id, 'name' => 'Financeiro', 'exists' => true])
        ->and($rows[1]['event']['value'])->toBe('department_created')
        ->and(array_keys($rows[0]))->toBe(['id', 'created_at', 'event', 'author', 'entity']);
});

test('ordering can be reversed and falls back to a stable tie breaker', function () {
    $department = Department::factory()->create();
    $first = auditEvent($department, $this->admin, AdminEvent::DepartmentUpdated, ['name' => 'a'], ['name' => 'b']);
    $second = auditEvent($department, $this->admin, AdminEvent::DepartmentUpdated, ['name' => 'b'], ['name' => 'c']);

    $asc = auditProps($this->actingAs($this->admin)->get('/audit?direction=asc'))['logs']['data'];
    $desc = auditProps($this->actingAs($this->admin)->get('/audit'))['logs']['data'];

    expect(collect($asc)->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and(collect($desc)->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('events without a user are listed and labelled without an author', function () {
    $department = Department::factory()->create(['name' => 'Suporte']);
    auditEvent($department, null, AdminEvent::DepartmentCreated, null, ['name' => 'Suporte']);

    $row = auditProps($this->actingAs($this->admin)->get('/audit'))['logs']['data'][0];

    expect($row['author'])->toBeNull();
});

test('events whose entity no longer exists keep their historical data', function () {
    AuditLog::query()->forceCreate([
        'user_id' => $this->admin->id,
        'event_type' => AdminEvent::CategoryCreated,
        'auditable_type' => \App\Models\TicketCategory::class,
        'auditable_id' => 9999,
        'old_values' => null,
        'new_values' => ['name' => 'Impressoras', 'is_active' => true],
        'created_at' => '2026-10-21 10:00:00',
    ]);

    $props = auditProps($this->actingAs($this->admin)->get('/audit?selected=1'));

    expect($props['logs']['data'][0]['entity'])->toBe(['type' => 'Categoria', 'id' => 9999, 'name' => 'Impressoras', 'exists' => false])
        ->and($props['detail']['changes'])->toBe([
            ['field' => 'Nome', 'from' => null, 'to' => 'Impressoras'],
            ['field' => 'Situação', 'from' => null, 'to' => 'Ativo'],
        ]);
});

test('SLA policy events are identified by priority and show targets in readable units', function () {
    $policy = SlaPolicy::factory()->create(['priority' => 'high']);
    $log = auditEvent($policy, $this->admin, AdminEvent::SlaPolicyUpdated,
        ['priority' => 'high', 'first_response_minutes' => 60, 'resolution_minutes' => 480],
        ['priority' => 'high', 'first_response_minutes' => 90, 'resolution_minutes' => 480]);

    $props = auditProps($this->actingAs($this->admin)->get("/audit?selected={$log->id}"));

    expect($props['logs']['data'][0]['entity']['name'])->toBe('Prioridade Alta')
        ->and($props['detail']['changes'])->toContain(['field' => 'Meta de primeira resposta', 'from' => '1 h', 'to' => '1 h 30 min']);
});

/* ---------- Filtros ---------- */

test('author search matches name or e-mail and treats wildcards literally', function () {
    $bob = User::factory()->withRole('admin')->create(['name' => 'Bob 100%', 'email' => 'bob@example.com']);
    $department = Department::factory()->create();
    auditEvent($department, $this->admin, AdminEvent::DepartmentUpdated, ['name' => 'a'], ['name' => 'b']);
    auditEvent($department, $bob, AdminEvent::DepartmentUpdated, ['name' => 'b'], ['name' => 'c']);

    $authors = fn (string $q) => collect(auditProps($this->actingAs($this->admin)->get('/audit?'.http_build_query(['q' => $q])))['logs']['data'])
        ->pluck('author')->all();

    expect($authors('alice'))->toBe(['Alice Admin'])
        ->and($authors('bob@example'))->toBe(['Bob 100%'])
        ->and($authors('100%'))->toBe(['Bob 100%'])
        ->and($authors('%'))->toBe(['Bob 100%'])
        ->and($authors('_'))->toBe([])
        ->and($authors('ninguem'))->toBe([]);
});

test('event and entity filters narrow the listing', function () {
    $department = Department::factory()->create();
    $role = Role::create(['name' => 'Auditado', 'slug' => 'auditado']);
    auditEvent($department, $this->admin, AdminEvent::DepartmentCreated, null, ['name' => 'D']);
    auditEvent($role, $this->admin, AdminEvent::RoleCreated, null, ['name' => 'Auditado']);
    auditEvent($role, $this->admin, AdminEvent::RoleUpdated, ['name' => 'Auditado'], ['name' => 'Auditado 2']);

    $ids = fn (array $query) => collect(auditProps($this->actingAs($this->admin)->get('/audit?'.http_build_query($query)))['logs']['data'])
        ->pluck('event.value')->sort()->values()->all();

    expect($ids(['event' => 'role_created']))->toBe(['role_created'])
        ->and($ids(['entity' => 'role']))->toBe(['role_created', 'role_updated'])
        ->and($ids(['entity' => 'department']))->toBe(['department_created'])
        ->and($ids(['entity' => 'role', 'entity_id' => $role->id, 'event' => 'role_updated']))->toBe(['role_updated'])
        ->and($ids(['entity' => 'department', 'entity_id' => $role->id]))->toBe([]);
});

test('the date range is inclusive, in UTC, on both ends', function () {
    $department = Department::factory()->create();
    foreach (['2026-10-19 23:59:59', '2026-10-20 00:00:00', '2026-10-21 23:59:59', '2026-10-22 00:00:00'] as $at) {
        auditEvent($department, $this->admin, AdminEvent::DepartmentUpdated, ['name' => 'a'], ['name' => 'b'], $at);
    }

    $dates = fn (array $query) => collect(auditProps($this->actingAs($this->admin)->get('/audit?'.http_build_query($query)))['logs']['data'])
        ->pluck('created_at')->reverse()->values()->all();

    expect($dates(['from' => '2026-10-20', 'to' => '2026-10-21']))->toBe(['2026-10-20T00:00:00+00:00', '2026-10-21T23:59:59+00:00'])
        ->and($dates(['from' => '2026-10-22']))->toBe(['2026-10-22T00:00:00+00:00'])
        ->and($dates(['to' => '2026-10-19']))->toBe(['2026-10-19T23:59:59+00:00'])
        ->and($dates(['from' => '2026-10-21', 'to' => '2026-10-21']))->toBe(['2026-10-21T23:59:59+00:00']);
});

test('invalid filters are rejected with messages and return to the default listing', function (array $query, string $field) {
    $this->actingAs($this->admin)
        ->from('/audit')
        ->get('/audit?'.http_build_query($query))
        ->assertRedirect('/audit')
        ->assertSessionHasErrors($field);
})->with([
    'end before start' => [['from' => '2026-10-21', 'to' => '2026-10-20'], 'to'],
    'range too long' => [['from' => '2025-01-01', 'to' => '2026-10-20'], 'to'],
    'bad date' => [['from' => '21/10/2026'], 'from'],
    'impossible date' => [['to' => '2026-02-31'], 'to'],
    'unknown event' => [['event' => 'drop_table'], 'event'],
    'unknown entity' => [['entity' => 'App\\Models\\User'], 'entity'],
    'id without entity' => [['entity_id' => '3'], 'entity'],
    'non numeric id' => [['entity' => 'user', 'entity_id' => 'abc'], 'entity_id'],
    'bad direction' => [['direction' => 'sideways'], 'direction'],
    'long search' => [['q' => str_repeat('a', 101)], 'q'],
    'bad page' => [['page' => '0'], 'page'],
]);

test('a range of exactly the maximum length is accepted', function () {
    $this->actingAs($this->admin)->get('/audit?from=2025-10-20&to=2026-10-20')->assertOk();
});

/* ---------- Paginação ---------- */

test('pagination happens on the server and keeps the filters', function () {
    $department = Department::factory()->create();
    foreach (range(1, 20) as $i) {
        auditEvent($department, $this->admin, AdminEvent::DepartmentUpdated, ['name' => 'a'], ['name' => 'b'], '2026-10-21 10:00:00');
    }
    auditEvent($department, $this->admin, AdminEvent::DepartmentCreated, null, ['name' => 'x']);

    $logs = auditProps($this->actingAs($this->admin)->get('/audit?event=department_updated'))['logs'];

    expect($logs['data'])->toHaveCount(15)
        ->and($logs['total'])->toBe(20)
        ->and($logs['per_page'])->toBe(15)
        ->and($logs['next_page_url'])->toContain('event=department_updated')
        ->and(auditProps($this->actingAs($this->admin)->get('/audit?event=department_updated&page=2'))['logs']['data'])->toHaveCount(5);
});

test('the listing runs a constant number of queries regardless of the page size', function () {
    $department = Department::factory()->create();
    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/audit')->assertOk();

        return count(DB::getQueryLog());
    };

    // Um evento de cada tipo de entidade, para que as duas medições usem as mesmas relações.
    auditEvent($department, $this->admin, AdminEvent::DepartmentUpdated, ['name' => 'a'], ['name' => 'b']);
    auditEvent(User::factory()->create(), $this->admin, AdminEvent::UserUpdated, ['name' => 'a'], ['name' => 'b']);
    $queries(); // aquecimento: o papel e as permissões do operador ficam em cache no modelo
    $few = $queries();

    foreach (range(1, 13) as $i) {
        auditEvent(User::factory()->create(), $this->admin, AdminEvent::UserUpdated, ['name' => 'a'], ['name' => 'b']);
        auditEvent($department, $this->admin, AdminEvent::DepartmentUpdated, ['name' => 'a'], ['name' => 'b']);
    }

    expect($queries())->toBe($few);
});

/* ---------- Detalhes ---------- */

test('details show readable old and new values from a closed list of fields', function () {
    $role = Role::create(['name' => 'Revisores', 'slug' => 'revisores']);
    $log = auditEvent($role, $this->admin, AdminEvent::RolePermissionsUpdated,
        ['permissions' => ['tickets.assign']],
        ['permissions' => ['tickets.assign', 'audit.view'], 'added' => ['audit.view'], 'removed' => []]);

    $detail = auditProps($this->actingAs($this->admin)->get("/audit?selected={$log->id}"))['detail'];

    expect($detail['event']['label'])->toBe('Permissões do papel alteradas')
        ->and($detail['entity']['type'])->toBe('Papel')
        ->and($detail['changes'])->toBe([['field' => 'Permissões concedidas', 'from' => null, 'to' => ['audit.view']]]);
});

test('details never expose sensitive keys that end up in the stored payload', function () {
    $user = User::factory()->create();
    $hash = '$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ0123456';
    $log = auditEvent($user, $this->admin, AdminEvent::UserUpdated,
        ['name' => 'Antigo', 'password' => $hash, 'remember_token' => 'tok-old', 'api_token' => 'secret-1'],
        ['name' => 'Novo', 'password' => $hash, 'remember_token' => 'tok-new', 'two_factor_secret' => 'secret-2', 'cookie' => 'laravel_session=abc']);

    $response = $this->actingAs($this->admin)->get("/audit?selected={$log->id}");
    $detail = auditProps($response)['detail'];

    expect($detail['changes'])->toBe([['field' => 'Nome', 'from' => 'Antigo', 'to' => 'Novo']]);

    foreach ([$hash, 'tok-old', 'tok-new', 'secret-1', 'secret-2', 'laravel_session'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
});

test('values of an unexpected type are dropped instead of forwarded', function () {
    $user = User::factory()->create();
    $log = auditEvent($user, $this->admin, AdminEvent::UserUpdated,
        ['name' => ['nested' => 'x'], 'is_active' => 'yes'],
        ['name' => ['nested' => 'x'], 'is_active' => 'yes', 'role' => 'texto solto']);

    expect(auditProps($this->actingAs($this->admin)->get("/audit?selected={$log->id}"))['detail']['changes'])->toBe([]);
});

test('markup inside stored values is returned as plain text for the interface to escape', function () {
    $department = Department::factory()->create();
    $log = auditEvent($department, $this->admin, AdminEvent::DepartmentUpdated,
        ['name' => 'ok'], ['name' => '<img src=x onerror=alert(1)>']);

    $detail = auditProps($this->actingAs($this->admin)->get("/audit?selected={$log->id}"))['detail'];

    expect($detail['changes'][0]['to'])->toBe('<img src=x onerror=alert(1)>');
    expect(file_get_contents(base_path('resources/js/Pages/Audit/Index.vue')))->not->toContain('v-html');
});

test('an unknown event identifier opens the not found state', function () {
    $props = auditProps($this->actingAs($this->admin)->get('/audit?selected=987654'));

    expect($props['detail'])->toBeNull()
        ->and($props['selected'])->toBe(987654);
});

/* ---------- Somente leitura ---------- */

test('there are no routes to change or delete audit events', function () {
    $department = Department::factory()->create();
    $log = auditEvent($department, $this->admin, AdminEvent::DepartmentUpdated, ['name' => 'a'], ['name' => 'b']);

    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->actingAs($this->admin)->{$method}('/audit', ['selected' => $log->id])->assertStatus(405);
        $this->actingAs($this->admin)->{$method}("/audit/{$log->id}")->assertNotFound();
    }

    expect(AuditLog::count())->toBe(1)
        ->and($log->fresh()->new_values)->toBe(['name' => 'b']);
});

test('consulting the trail does not create or change audit events', function () {
    $department = Department::factory()->create();
    $log = auditEvent($department, $this->admin, AdminEvent::DepartmentUpdated, ['name' => 'a'], ['name' => 'b']);
    $before = AuditLog::query()->get()->toArray();

    $this->actingAs($this->admin)->get('/audit')->assertOk();
    $this->actingAs($this->admin)->get("/audit?selected={$log->id}&event=department_updated&direction=asc")->assertOk();

    expect(AuditLog::query()->get()->toArray())->toBe($before);
});

test('the listing shares the audit permission for the navigation', function () {
    $this->actingAs($this->admin)->get('/audit')->assertInertia(fn (Assert $page) => $page
        ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('audit.view')));
});
