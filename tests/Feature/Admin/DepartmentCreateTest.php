<?php

use App\Enums\AdminEvent;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
});

/** Dados válidos para o cadastro, com ajustes por teste. */
function newDepartmentData(array $overrides = []): array
{
    return [
        'name' => 'Tecnologia da Informação',
        'description' => 'Infraestrutura, sistemas e suporte aos usuários.',
        'status' => 'active',
        'submission_token' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/** Papel personalizado só com a permissão informada. */
function operatorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Apenas {$permission}", 'slug' => 'only-'.str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/admin/departments/create')->assertRedirect('/login');
    $this->post('/admin/departments', newDepartmentData())->assertRedirect('/login');

    expect(Department::count())->toBe(0);
});

test('users without departments.manage can neither open the form nor create', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get('/admin/departments/create')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->missing('statuses'));

    $this->actingAs($user)->post('/admin/departments', newDepartmentData())->assertForbidden();

    expect(Department::count())->toBe(0);
})->with(['requester', 'technician', 'manager']);

test('users.manage alone does not allow creating departments', function () {
    $this->actingAs(operatorWithOnly('users.manage'))->post('/admin/departments', newDepartmentData())->assertForbidden();

    expect(Department::count())->toBe(0);
});

test('inactive administrators cannot create departments', function () {
    $inactive = User::factory()->withRole('admin')->inactive()->create();

    $this->actingAs($inactive)->get('/admin/departments/create')->assertForbidden();
    $this->actingAs($inactive)->post('/admin/departments', newDepartmentData())->assertForbidden();

    expect(Department::count())->toBe(0);
});

test('the form receives the real limits, statuses and a submission token', function () {
    $this->actingAs($this->admin)
        ->get('/admin/departments/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Departments/Create')
            ->where('limits', ['name' => 255, 'description' => 1000])
            ->where('statuses', [
                ['value' => 'active', 'label' => 'Ativo'],
                ['value' => 'inactive', 'label' => 'Inativo'],
            ])
            ->where('submissionToken', fn ($token) => Str::isUuid($token)));
});

/* ---------- Cadastro ---------- */

test('authorized users create a department and return to the listing', function () {
    $this->actingAs($this->admin)
        ->post('/admin/departments', newDepartmentData())
        ->assertRedirect(route('admin.departments.index', ['q' => 'Tecnologia da Informação']))
        ->assertSessionHas('success', 'Departamento Tecnologia da Informação cadastrado.')
        ->assertSessionHasNoErrors();

    $department = Department::sole();

    expect($department->name)->toBe('Tecnologia da Informação')
        ->and($department->description)->toBe('Infraestrutura, sistemas e suporte aos usuários.')
        ->and($department->is_active)->toBeTrue();

    // A listagem de destino existe e mostra o departamento criado.
    $this->actingAs($this->admin)
        ->get(route('admin.departments.index', ['q' => 'Tecnologia da Informação']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('departments.data.0.id', $department->id));
});

test('a permission granted to a custom role is enough', function () {
    $this->actingAs(operatorWithOnly('departments.manage'))
        ->post('/admin/departments', newDepartmentData())
        ->assertSessionHasNoErrors();

    expect(Department::count())->toBe(1);
});

test('departments can be created as inactive', function () {
    $this->actingAs($this->admin)
        ->post('/admin/departments', newDepartmentData(['status' => 'inactive']))
        ->assertSessionHas('success', 'Departamento Tecnologia da Informação cadastrado como inativo.');

    expect(Department::sole()->is_active)->toBeFalse();
});

test('the description is optional and blank values become null', function () {
    $this->actingAs($this->admin)
        ->post('/admin/departments', newDepartmentData(['description' => '   ']))
        ->assertSessionHasNoErrors();

    expect(Department::sole()->description)->toBeNull();
});

test('surrounding spaces are removed from the name', function () {
    $this->actingAs($this->admin)->post('/admin/departments', newDepartmentData(['name' => '  Financeiro  ']));

    expect(Department::sole()->name)->toBe('Financeiro');
});

test('fields outside the form are not persisted', function () {
    $this->actingAs($this->admin)
        ->post('/admin/departments', newDepartmentData([
            'id' => 9999,
            'is_active' => false,
            'created_at' => '2000-01-01 00:00:00',
        ]))
        ->assertSessionHasNoErrors();

    $department = Department::sole();

    expect($department->id)->not->toBe(9999)
        ->and($department->is_active)->toBeTrue()
        ->and($department->created_at->year)->not->toBe(2000);
});

/* ---------- Validação ---------- */

test('invalid values are rejected', function (array $overrides, string $field) {
    $this->actingAs($this->admin)
        ->post('/admin/departments', newDepartmentData($overrides))
        ->assertSessionHasErrors($field);

    expect(Department::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'blank name' => [['name' => '    '], 'name'],
    'name as array' => [['name' => ['x']], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'description too long' => [['description' => str_repeat('a', 1001)], 'description'],
    'description as array' => [['description' => ['x']], 'description'],
    'missing status' => [['status' => ''], 'status'],
    'unknown status' => [['status' => 'archived'], 'status'],
    'missing token' => [['submission_token' => ''], 'submission_token'],
    'invalid token' => [['submission_token' => 'abc'], 'submission_token'],
]);

test('the limits accept values at the boundary', function () {
    $this->actingAs($this->admin)
        ->post('/admin/departments', newDepartmentData(['name' => str_repeat('a', 255), 'description' => str_repeat('b', 1000)]))
        ->assertSessionHasNoErrors();

    expect(Department::count())->toBe(1);
});

test('validation messages are in Portuguese', function () {
    $this->actingAs($this->admin)
        ->post('/admin/departments', newDepartmentData(['name' => '']))
        ->assertSessionHasErrors(['name' => 'O campo nome do departamento é obrigatório.']);
});

/* ---------- Envio repetido ---------- */

test('repeating the same submission does not create a duplicate', function () {
    $data = newDepartmentData();

    $first = $this->actingAs($this->admin)->post('/admin/departments', $data);
    $second = $this->actingAs($this->admin)->post('/admin/departments', $data);

    expect(Department::count())->toBe(1)
        ->and(AuditLog::count())->toBe(1);
    $second->assertRedirect($first->headers->get('Location'));
});

test('a new form allows another department with the same name', function () {
    $this->actingAs($this->admin)->post('/admin/departments', newDepartmentData());
    $this->actingAs($this->admin)->post('/admin/departments', newDepartmentData())->assertSessionHasNoErrors();

    // O PRD não exige nomes únicos para departamentos.
    expect(Department::where('name', 'Tecnologia da Informação')->count())->toBe(2);
});

/* ---------- Auditoria e atomicidade ---------- */

test('the creation is audited with the operator', function () {
    $this->actingAs($this->admin)->post('/admin/departments', newDepartmentData());

    $department = Department::sole();
    $log = AuditLog::sole();

    expect($log->event_type)->toBe(AdminEvent::DepartmentCreated)
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->auditable->is($department))->toBeTrue()
        ->and($log->created_at)->not->toBeNull()
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values)->toBe([
            'name' => 'Tecnologia da Informação',
            'description' => 'Infraestrutura, sistemas e suporte aos usuários.',
            'is_active' => true,
        ]);
});

test('a failure while auditing leaves no department and allows a retry', function () {
    $data = newDepartmentData();
    AuditLog::creating(fn () => throw new RuntimeException('falha simulada'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->admin)->post('/admin/departments', $data))
        ->toThrow(RuntimeException::class, 'falha simulada');

    expect(Department::count())->toBe(0);

    // O token volta a valer: a nova tentativa grava normalmente.
    AuditLog::flushEventListeners();
    $this->actingAs($this->admin)->post('/admin/departments', $data)->assertSessionHasNoErrors();

    expect(Department::count())->toBe(1);
});
