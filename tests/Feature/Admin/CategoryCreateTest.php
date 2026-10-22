<?php

use App\Enums\AdminEvent;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
});

/** Dados válidos para o cadastro, com ajustes por teste. */
function newCategoryData(array $overrides = []): array
{
    return [
        'name' => 'Hardware',
        'description' => 'Computadores, monitores, impressoras e periféricos.',
        'status' => 'active',
        'submission_token' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/** Papel personalizado só com a permissão informada. */
function categoryCreatorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Só {$permission}", 'slug' => 'creator-'.str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/admin/categories/create')->assertRedirect('/login');
    $this->post('/admin/categories', newCategoryData())->assertRedirect('/login');

    expect(TicketCategory::count())->toBe(0);
});

test('users without categories.manage can neither open the form nor create', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get('/admin/categories/create')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->missing('statuses'));

    $this->actingAs($user)->post('/admin/categories', newCategoryData())->assertForbidden();

    expect(TicketCategory::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
})->with(['requester', 'technician', 'manager']);

test('other permissions do not allow creating categories', function (string $permission) {
    $this->actingAs(categoryCreatorWithOnly($permission))->post('/admin/categories', newCategoryData())->assertForbidden();

    expect(TicketCategory::count())->toBe(0);
})->with(['users.manage', 'departments.manage', 'tickets.change_category']);

test('inactive administrators cannot create categories', function () {
    $inactive = User::factory()->withRole('admin')->inactive()->create();

    $this->actingAs($inactive)->get('/admin/categories/create')->assertForbidden();
    $this->actingAs($inactive)->post('/admin/categories', newCategoryData())->assertForbidden();

    expect(TicketCategory::count())->toBe(0);
});

test('the form receives the real limits, statuses and a submission token', function () {
    $this->actingAs($this->admin)
        ->get('/admin/categories/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Categories/Create')
            ->where('limits', ['name' => 255, 'description' => 1000])
            ->where('statuses', [
                ['value' => 'active', 'label' => 'Ativa'],
                ['value' => 'inactive', 'label' => 'Inativa'],
            ])
            ->where('submissionToken', fn ($token) => Str::isUuid($token)));
});

/* ---------- Cadastro ---------- */

test('authorized users create a category and return to the listing', function () {
    $this->actingAs($this->admin)
        ->post('/admin/categories', newCategoryData())
        ->assertRedirect(route('admin.categories.index', ['q' => 'Hardware']))
        ->assertSessionHas('success', 'Categoria Hardware cadastrada.')
        ->assertSessionHasNoErrors();

    $category = TicketCategory::sole();

    expect($category->name)->toBe('Hardware')
        ->and($category->description)->toBe('Computadores, monitores, impressoras e periféricos.')
        ->and($category->is_active)->toBeTrue()
        ->and($category->created_at)->not->toBeNull();

    // A listagem de destino existe e mostra a categoria criada.
    $this->actingAs($this->admin)
        ->get(route('admin.categories.index', ['q' => 'Hardware']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('categories.data.0.id', $category->id));
});

test('a permission granted to a custom role is enough', function () {
    $this->actingAs(categoryCreatorWithOnly('categories.manage'))
        ->post('/admin/categories', newCategoryData())
        ->assertSessionHasNoErrors();

    expect(TicketCategory::count())->toBe(1);
});

test('categories can be created as inactive and are not offered for new tickets', function () {
    $this->actingAs($this->admin)
        ->post('/admin/categories', newCategoryData(['status' => 'inactive']))
        ->assertSessionHas('success', 'Categoria Hardware cadastrada como inativa.');

    expect(TicketCategory::sole()->is_active)->toBeFalse();

    $this->actingAs(User::factory()->withRole('requester')->create())
        ->get('/tickets/create')
        ->assertInertia(fn (Assert $page) => $page->where('categories', []));
});

test('active categories become available for new tickets', function () {
    $this->actingAs($this->admin)->post('/admin/categories', newCategoryData());

    $this->actingAs(User::factory()->withRole('requester')->create())
        ->get('/tickets/create')
        ->assertInertia(fn (Assert $page) => $page->where('categories.0.label', 'Hardware'));
});

test('the description is optional and blank values become null', function () {
    $this->actingAs($this->admin)
        ->post('/admin/categories', newCategoryData(['description' => '   ']))
        ->assertSessionHasNoErrors();

    expect(TicketCategory::sole()->description)->toBeNull();
});

test('surrounding spaces are removed from the name and description', function () {
    $this->actingAs($this->admin)->post('/admin/categories', newCategoryData(['name' => '  Rede  ', 'description' => '  VPN  ']));

    expect(TicketCategory::sole()->only(['name', 'description']))->toBe(['name' => 'Rede', 'description' => 'VPN']);
});

test('fields outside the form are not persisted', function () {
    $this->actingAs($this->admin)
        ->post('/admin/categories', newCategoryData([
            'id' => 9999,
            'is_active' => false,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ]))
        ->assertSessionHasNoErrors();

    $category = TicketCategory::sole();

    expect($category->id)->not->toBe(9999)
        ->and($category->is_active)->toBeTrue()
        ->and($category->created_at->year)->not->toBe(2000)
        ->and($category->updated_at->year)->not->toBe(2000);
});

/* ---------- Validação ---------- */

test('invalid values are rejected', function (array $overrides, string $field) {
    $this->actingAs($this->admin)
        ->post('/admin/categories', newCategoryData($overrides))
        ->assertSessionHasErrors($field);

    expect(TicketCategory::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'blank name' => [['name' => '    '], 'name'],
    'name as array' => [['name' => ['x']], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'description too long' => [['description' => str_repeat('a', 1001)], 'description'],
    'description as array' => [['description' => ['x']], 'description'],
    'missing status' => [['status' => ''], 'status'],
    'unknown status' => [['status' => 'archived'], 'status'],
    'status as array' => [['status' => ['active']], 'status'],
    'missing token' => [['submission_token' => ''], 'submission_token'],
    'invalid token' => [['submission_token' => 'abc'], 'submission_token'],
]);

test('the limits accept values at the boundary', function () {
    $this->actingAs($this->admin)
        ->post('/admin/categories', newCategoryData(['name' => str_repeat('a', 255), 'description' => str_repeat('b', 1000)]))
        ->assertSessionHasNoErrors();

    expect(TicketCategory::count())->toBe(1);
});

test('validation messages are in Portuguese', function () {
    $this->actingAs($this->admin)
        ->post('/admin/categories', newCategoryData(['name' => '']))
        ->assertSessionHasErrors(['name' => 'O campo nome da categoria é obrigatório.']);
});

/* ---------- Envio repetido e unicidade ---------- */

test('repeating the same submission does not create a duplicate', function () {
    $data = newCategoryData();

    $first = $this->actingAs($this->admin)->post('/admin/categories', $data);
    $second = $this->actingAs($this->admin)->post('/admin/categories', $data);

    expect(TicketCategory::count())->toBe(1)
        ->and(AuditLog::count())->toBe(1);
    $second->assertRedirect($first->headers->get('Location'));
});

test('a new form allows another category with the same name', function () {
    $this->actingAs($this->admin)->post('/admin/categories', newCategoryData());
    $this->actingAs($this->admin)->post('/admin/categories', newCategoryData())->assertSessionHasNoErrors();

    // O PRD (RF-04) não exige nomes únicos para categorias.
    expect(TicketCategory::where('name', 'Hardware')->count())->toBe(2);
});

/* ---------- Auditoria, atomicidade e integridade ---------- */

test('the creation is audited with the operator', function () {
    $this->actingAs($this->admin)->post('/admin/categories', newCategoryData());

    $category = TicketCategory::sole();
    $log = AuditLog::sole();

    expect($log->event_type)->toBe(AdminEvent::CategoryCreated)
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->auditable->is($category))->toBeTrue()
        ->and($log->created_at)->not->toBeNull()
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values)->toBe([
            'name' => 'Hardware',
            'description' => 'Computadores, monitores, impressoras e periféricos.',
            'is_active' => true,
        ]);
});

test('a failure while auditing leaves no category and allows a retry', function () {
    $data = newCategoryData();
    AuditLog::creating(fn () => throw new RuntimeException('falha simulada'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->admin)->post('/admin/categories', $data))
        ->toThrow(RuntimeException::class, 'falha simulada');

    expect(TicketCategory::count())->toBe(0);

    // O token volta a valer: a nova tentativa grava normalmente.
    AuditLog::flushEventListeners();
    $this->actingAs($this->admin)->post('/admin/categories', $data)->assertSessionHasNoErrors();

    expect(TicketCategory::count())->toBe(1);
});

test('creating a category does not change existing categories or tickets', function () {
    $existing = TicketCategory::factory()->create(['name' => 'Hardware', 'is_active' => false]);
    $ticket = Ticket::factory()->create(['category_id' => $existing->id]);
    $categorySnapshot = $existing->fresh()->getAttributes();
    $ticketSnapshot = $ticket->fresh()->getAttributes();
    $this->travel(5)->minutes();

    $this->actingAs($this->admin)->post('/admin/categories', newCategoryData())->assertSessionHasNoErrors();

    expect(TicketCategory::count())->toBe(2)
        ->and($existing->fresh()->getAttributes())->toBe($categorySnapshot)
        ->and($ticket->fresh()->getAttributes())->toBe($ticketSnapshot);
});
