<?php

use App\Enums\AdminEvent;
use App\Enums\TicketEvent;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
    $this->department = Department::factory()->create([
        'name' => 'Suporte',
        'description' => 'Atendimento de primeiro nível.',
    ]);
});

/** Dados atuais do departamento como o formulário os envia, com ajustes por teste. */
function departmentEditData(Department $department, array $overrides = []): array
{
    $department->refresh();

    return [
        'name' => $department->name,
        'description' => $department->description,
        'status' => $department->is_active ? 'active' : 'inactive',
        'version' => $department->editVersion(),
        ...$overrides,
    ];
}

function patchDepartment(User $operator, Department $department, array $data): TestResponse
{
    return test()->actingAs($operator)->patch("/admin/departments/{$department->id}", $data);
}

/** Papel personalizado só com a permissão informada. */
function departmentEditorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Só {$permission}", 'slug' => 'just-'.str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get("/admin/departments/{$this->department->id}/edit")->assertRedirect('/login');
    $this->patch("/admin/departments/{$this->department->id}", departmentEditData($this->department, ['name' => 'X']))
        ->assertRedirect('/login');

    expect($this->department->fresh()->name)->toBe('Suporte');
});

test('users without departments.manage can neither view nor edit', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get("/admin/departments/{$this->department->id}/edit")
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->missing('department'));

    patchDepartment($user, $this->department, departmentEditData($this->department, ['name' => 'Invasão', 'status' => 'inactive']))
        ->assertForbidden();

    expect($this->department->fresh())
        ->name->toBe('Suporte')
        ->is_active->toBeTrue();
})->with(['requester', 'technician', 'manager']);

test('users.manage alone does not allow editing departments', function () {
    patchDepartment(departmentEditorWithOnly('users.manage'), $this->department, departmentEditData($this->department, ['name' => 'X']))
        ->assertForbidden();
});

test('a custom role with departments.manage can edit', function () {
    patchDepartment(departmentEditorWithOnly('departments.manage'), $this->department, departmentEditData($this->department, ['name' => 'Suporte N1']))
        ->assertSessionHasNoErrors();

    expect($this->department->fresh()->name)->toBe('Suporte N1');
});

test('inactive administrators are denied', function () {
    $inactive = User::factory()->withRole('admin')->inactive()->create();

    $this->actingAs($inactive)->get("/admin/departments/{$this->department->id}/edit")->assertForbidden();
    patchDepartment($inactive, $this->department, departmentEditData($this->department, ['name' => 'X']))->assertForbidden();
});

test('unauthorized users cannot tell which identifiers exist', function () {
    $requester = User::factory()->withRole('requester')->create();

    $this->actingAs($requester)->get('/admin/departments/999999/edit')->assertForbidden();
    $this->actingAs($requester)->patch('/admin/departments/999999', ['name' => 'x'])->assertForbidden();
});

test('missing departments return not found to authorized operators', function () {
    $this->actingAs($this->admin)->get('/admin/departments/999999/edit')->assertNotFound();
    $this->actingAs($this->admin)->patch('/admin/departments/999999', ['name' => 'x'])->assertNotFound();
});

/* ---------- Carregamento ---------- */

test('the form receives the real data of the selected department', function () {
    Department::factory()->create(['name' => 'Outro']);
    User::factory()->count(2)->create(['department_id' => $this->department->id]);
    User::factory()->inactive()->create(['department_id' => $this->department->id]);
    Ticket::factory()->count(3)->create(['department_id' => $this->department->id]);

    $this->actingAs($this->admin)
        ->get("/admin/departments/{$this->department->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Departments/Edit')
            ->where('department', [
                'id' => $this->department->id,
                'name' => 'Suporte',
                'is_active' => true,
                'users_count' => 3,
                'active_users_count' => 2,
                'tickets_count' => 3,
                'created_at' => $this->department->created_at->toIso8601String(),
                'updated_at' => $this->department->updated_at->toIso8601String(),
            ])
            ->where('values', [
                'name' => 'Suporte',
                'description' => 'Atendimento de primeiro nível.',
                'status' => 'active',
            ])
            ->where('limits', ['name' => 255, 'description' => 1000])
            ->has('statuses', 2)
            ->where('version', $this->department->editVersion()));
});

test('a department without description loads an empty field', function () {
    $this->department->update(['description' => null]);

    $this->actingAs($this->admin)
        ->get("/admin/departments/{$this->department->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('values.description', ''));
});

/* ---------- Atualização ---------- */

test('valid changes are persisted and the operator returns to the listing', function () {
    patchDepartment($this->admin, $this->department, departmentEditData($this->department, [
        'name' => '  Suporte Técnico  ',
        'description' => 'Atendimento de primeiro e segundo nível.',
    ]))
        ->assertRedirect(route('admin.departments.index', ['q' => 'Suporte Técnico']))
        ->assertSessionHas('success', 'Alterações do departamento Suporte Técnico salvas.');

    expect($this->department->fresh())
        ->name->toBe('Suporte Técnico')
        ->description->toBe('Atendimento de primeiro e segundo nível.')
        ->is_active->toBeTrue();

    $this->actingAs($this->admin)
        ->get(route('admin.departments.index', ['q' => 'Suporte Técnico']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('departments.data.0.id', $this->department->id));
});

test('the description can be cleared', function () {
    patchDepartment($this->admin, $this->department, departmentEditData($this->department, ['description' => '   ']))
        ->assertSessionHasNoErrors();

    expect($this->department->fresh()->description)->toBeNull();
});

test('the department may keep its name or share it with another one', function () {
    Department::factory()->create(['name' => 'Financeiro']);

    patchDepartment($this->admin, $this->department, departmentEditData($this->department, ['description' => 'Nova descrição']))
        ->assertSessionHasNoErrors();

    // O PRD não exige nomes únicos, como na criação (ADM-05).
    patchDepartment($this->admin, $this->department, departmentEditData($this->department, ['name' => 'Financeiro']))
        ->assertSessionHasNoErrors();

    expect(Department::where('name', 'Financeiro')->count())->toBe(2);
});

test('saving without changes records nothing', function () {
    patchDepartment($this->admin, $this->department, departmentEditData($this->department))
        ->assertRedirect(route('admin.departments.edit', $this->department))
        ->assertSessionHas('info', 'Nenhuma alteração para salvar.');

    expect(AuditLog::count())->toBe(0);
});

test('a stale form is rejected as a conflict', function () {
    $stale = departmentEditData($this->department, ['name' => 'Versão antiga']);
    $this->department->update(['name' => 'Alterado por outra pessoa']);

    patchDepartment($this->admin, $this->department, $stale)->assertSessionHasErrors('conflict');

    expect($this->department->fresh()->name)->toBe('Alterado por outra pessoa')
        ->and(AuditLog::count())->toBe(0);
});

test('fields outside the form are not changed', function () {
    $createdAt = $this->department->created_at->toIso8601String();

    patchDepartment($this->admin, $this->department, departmentEditData($this->department, [
        'name' => 'Suporte Atualizado',
        'id' => 999999,
        'is_active' => false,
        'created_at' => '2000-01-01 00:00:00',
        'submission_token' => (string) Str::uuid(),
    ]))->assertSessionHasNoErrors();

    $fresh = $this->department->fresh();

    expect($fresh)->not->toBeNull()
        ->and(Department::find(999999))->toBeNull()
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->created_at->toIso8601String())->toBe($createdAt);
});

/* ---------- Validação ---------- */

test('invalid values are rejected without partial changes', function (array $overrides, string $field) {
    patchDepartment($this->admin, $this->department, departmentEditData($this->department, ['description' => 'Parcial', ...$overrides]))
        ->assertSessionHasErrors($field);

    expect($this->department->fresh()->description)->toBe('Atendimento de primeiro nível.');
})->with([
    'missing name' => [['name' => ''], 'name'],
    'blank name' => [['name' => '   '], 'name'],
    'name as array' => [['name' => ['x']], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'description too long' => [['description' => str_repeat('a', 1001)], 'description'],
    'unknown status' => [['status' => 'archived'], 'status'],
    'missing status' => [['status' => ''], 'status'],
    'missing version' => [['version' => ''], 'version'],
]);

/* ---------- Situação e integridade histórica ---------- */

test('deactivation keeps the record and every historical reference', function () {
    $member = User::factory()->withRole('requester')->create(['department_id' => $this->department->id]);
    $ticket = Ticket::factory()->create(['department_id' => $this->department->id, 'requester_id' => $member->id]);
    $ticket->histories()->forceCreate([
        'user_id' => $member->id,
        'event_type' => TicketEvent::Created,
        'new_values' => ['department' => ['id' => $this->department->id, 'label' => 'Suporte']],
        'created_at' => now(),
    ]);

    patchDepartment($this->admin, $this->department, departmentEditData($this->department, ['status' => 'inactive']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Departamento Suporte inativado. Os vínculos históricos foram preservados.');

    expect($this->department->fresh())->not->toBeNull()
        ->and($this->department->fresh()->is_active)->toBeFalse()
        ->and($ticket->fresh()->department_id)->toBe($this->department->id)
        ->and($member->fresh()->department_id)->toBe($this->department->id)
        ->and($ticket->histories()->sole()->new_values['department']['label'])->toBe('Suporte');

    // O departamento continua consultável na listagem e nos detalhes do chamado.
    $this->actingAs($this->admin)
        ->get('/admin/departments?status=inactive')
        ->assertInertia(fn (Assert $page) => $page
            ->where('departments.data.0.id', $this->department->id)
            ->where('departments.data.0.tickets_count', 1));
});

test('an inactive department can no longer be used in new links', function () {
    patchDepartment($this->admin, $this->department, departmentEditData($this->department, ['status' => 'inactive']));

    // Novos usuários (ADM-02) não podem ser associados a ele.
    $this->actingAs($this->admin)
        ->post('/admin/users', [
            'name' => 'Novo',
            'email' => 'novo@example.com',
            'role_id' => Role::where('slug', 'requester')->value('id'),
            'department_id' => $this->department->id,
            'status' => 'active',
        ])
        ->assertSessionHasErrors('department_id');
});

test('renaming keeps tickets linked and their history readable', function () {
    $ticket = Ticket::factory()->create(['department_id' => $this->department->id]);
    $ticket->histories()->forceCreate([
        'event_type' => TicketEvent::Created,
        'new_values' => ['department' => ['id' => $this->department->id, 'label' => 'Suporte']],
        'created_at' => now(),
    ]);

    patchDepartment($this->admin, $this->department, departmentEditData($this->department, ['name' => 'Central de Serviços']));

    expect($ticket->fresh()->department_id)->toBe($this->department->id)
        ->and($ticket->fresh()->department->name)->toBe('Central de Serviços')
        ->and($ticket->histories()->sole()->new_values['department']['label'])->toBe('Suporte');
});

test('reactivation is audited separately', function () {
    $inactive = Department::factory()->create(['name' => 'Legado', 'is_active' => false]);

    patchDepartment($this->admin, $inactive, departmentEditData($inactive, ['status' => 'active']))
        ->assertSessionHas('success', 'Departamento Legado reativado.');

    expect($inactive->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::sole()->event_type)->toBe(AdminEvent::DepartmentActivated);
});

/* ---------- Auditoria e atomicidade ---------- */

test('changes are audited with the operator and previous and new values', function () {
    patchDepartment($this->admin, $this->department, departmentEditData($this->department, [
        'name' => 'Suporte Técnico',
        'status' => 'inactive',
    ]));

    $logs = AuditLog::orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->event_type)->toBe(AdminEvent::DepartmentUpdated)
        ->and($logs[0]->user_id)->toBe($this->admin->id)
        ->and($logs[0]->auditable->is($this->department))->toBeTrue()
        ->and($logs[0]->created_at)->not->toBeNull()
        ->and($logs[0]->old_values)->toBe(['name' => 'Suporte'])
        ->and($logs[0]->new_values)->toBe(['name' => 'Suporte Técnico'])
        ->and($logs[1]->event_type)->toBe(AdminEvent::DepartmentDeactivated)
        ->and($logs[1]->old_values)->toBe(['is_active' => true])
        ->and($logs[1]->new_values)->toBe(['is_active' => false]);
});

test('a failure while auditing leaves no partial change', function () {
    AuditLog::creating(fn () => throw new RuntimeException('falha simulada'));

    $this->withoutExceptionHandling();

    expect(fn () => patchDepartment($this->admin, $this->department, departmentEditData($this->department, ['name' => 'Parcial', 'status' => 'inactive'])))
        ->toThrow(RuntimeException::class, 'falha simulada');

    expect($this->department->fresh())
        ->name->toBe('Suporte')
        ->is_active->toBeTrue();
});
