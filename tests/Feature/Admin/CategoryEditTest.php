<?php

use App\Enums\AdminEvent;
use App\Enums\TicketEvent;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole('admin')->create();
    $this->category = TicketCategory::factory()->create([
        'name' => 'Hardware',
        'description' => 'Computadores e periféricos.',
    ]);
});

/** Dados atuais da categoria como o formulário os envia, com ajustes por teste. */
function categoryEditData(TicketCategory $category, array $overrides = []): array
{
    $category->refresh();

    return [
        'name' => $category->name,
        'description' => $category->description,
        'status' => $category->is_active ? 'active' : 'inactive',
        'version' => $category->editVersion(),
        ...$overrides,
    ];
}

function patchCategory(User $operator, TicketCategory $category, array $data): TestResponse
{
    return test()->actingAs($operator)->patch("/admin/categories/{$category->id}", $data);
}

/** Papel personalizado só com a permissão informada. */
function categoryEditorWithOnly(string $permission): User
{
    $role = Role::create(['name' => "Editor {$permission}", 'slug' => 'editor-'.str_replace('.', '-', $permission)]);
    $role->permissions()->attach(Permission::where('slug', $permission)->value('id'), ['created_at' => now()]);

    return User::factory()->create(['role_id' => $role->id]);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get("/admin/categories/{$this->category->id}/edit")->assertRedirect('/login');
    $this->patch("/admin/categories/{$this->category->id}", categoryEditData($this->category, ['name' => 'X']))
        ->assertRedirect('/login');

    expect($this->category->fresh()->name)->toBe('Hardware');
});

test('users without categories.manage can neither view nor edit', function (string $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get("/admin/categories/{$this->category->id}/edit")
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->missing('category'));

    patchCategory($user, $this->category, categoryEditData($this->category, ['name' => 'Invasão', 'status' => 'inactive']))
        ->assertForbidden();

    expect($this->category->fresh())
        ->name->toBe('Hardware')
        ->is_active->toBeTrue()
        ->and(AuditLog::count())->toBe(0);
})->with(['requester', 'technician', 'manager']);

test('other permissions do not allow editing categories, not even the status', function (string $permission) {
    patchCategory(categoryEditorWithOnly($permission), $this->category, categoryEditData($this->category, ['status' => 'inactive']))
        ->assertForbidden();

    expect($this->category->fresh()->is_active)->toBeTrue();
})->with(['users.manage', 'departments.manage', 'tickets.change_category']);

test('a custom role with categories.manage can edit', function () {
    patchCategory(categoryEditorWithOnly('categories.manage'), $this->category, categoryEditData($this->category, ['name' => 'Hardware e Periféricos']))
        ->assertSessionHasNoErrors();

    expect($this->category->fresh()->name)->toBe('Hardware e Periféricos');
});

test('inactive administrators are denied', function () {
    $inactive = User::factory()->withRole('admin')->inactive()->create();

    $this->actingAs($inactive)->get("/admin/categories/{$this->category->id}/edit")->assertForbidden();
    patchCategory($inactive, $this->category, categoryEditData($this->category, ['name' => 'X']))->assertForbidden();
});

test('unauthorized users cannot tell which identifiers exist', function () {
    $requester = User::factory()->withRole('requester')->create();

    $this->actingAs($requester)->get('/admin/categories/999999/edit')->assertForbidden();
    $this->actingAs($requester)->patch('/admin/categories/999999', ['name' => 'x'])->assertForbidden();
});

test('missing categories return not found to authorized operators', function () {
    $this->actingAs($this->admin)->get('/admin/categories/999999/edit')->assertNotFound();
    $this->actingAs($this->admin)->patch('/admin/categories/999999', ['name' => 'x'])->assertNotFound();
});

test('non numeric identifiers do not match the route', function () {
    $this->actingAs($this->admin)->get('/admin/categories/abc/edit')->assertNotFound();
});

/* ---------- Carregamento ---------- */

test('the form receives the real data of the selected category', function () {
    TicketCategory::factory()->create(['name' => 'Outra']);
    Ticket::factory()->count(3)->create(['category_id' => $this->category->id]);

    $this->actingAs($this->admin)
        ->get("/admin/categories/{$this->category->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Categories/Edit')
            ->where('category', [
                'id' => $this->category->id,
                'name' => 'Hardware',
                'is_active' => true,
                'tickets_count' => 3,
                'created_at' => $this->category->created_at->toIso8601String(),
                'updated_at' => $this->category->updated_at->toIso8601String(),
            ])
            ->where('values', [
                'name' => 'Hardware',
                'description' => 'Computadores e periféricos.',
                'status' => 'active',
            ])
            ->where('limits', ['name' => 255, 'description' => 1000])
            ->where('statuses', [
                ['value' => 'active', 'label' => 'Ativa'],
                ['value' => 'inactive', 'label' => 'Inativa'],
            ])
            ->where('version', $this->category->editVersion()));
});

test('an inactive category without description loads its real state', function () {
    $this->category->update(['description' => null, 'is_active' => false]);

    $this->actingAs($this->admin)
        ->get("/admin/categories/{$this->category->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('values.description', '')
            ->where('values.status', 'inactive')
            ->where('category.is_active', false));
});

/* ---------- Atualização ---------- */

test('valid changes are persisted and the operator returns to the listing', function () {
    patchCategory($this->admin, $this->category, categoryEditData($this->category, [
        'name' => '  Hardware e Periféricos  ',
        'description' => 'Computadores, monitores e impressoras.',
    ]))
        ->assertRedirect(route('admin.categories.index', ['q' => 'Hardware e Periféricos']))
        ->assertSessionHas('success', 'Alterações da categoria Hardware e Periféricos salvas.');

    expect($this->category->fresh())
        ->name->toBe('Hardware e Periféricos')
        ->description->toBe('Computadores, monitores e impressoras.')
        ->is_active->toBeTrue();

    $this->actingAs($this->admin)
        ->get(route('admin.categories.index', ['q' => 'Hardware e Periféricos']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('categories.data.0.id', $this->category->id));
});

test('the description can be cleared', function () {
    patchCategory($this->admin, $this->category, categoryEditData($this->category, ['description' => '   ']))
        ->assertSessionHasNoErrors();

    expect($this->category->fresh()->description)->toBeNull();
});

test('the category may keep its name or share it with another one', function () {
    TicketCategory::factory()->create(['name' => 'Rede']);

    patchCategory($this->admin, $this->category, categoryEditData($this->category, ['description' => 'Nova descrição']))
        ->assertSessionHasNoErrors();

    // O PRD (RF-04) não exige nomes únicos, como na criação (ADM-08).
    patchCategory($this->admin, $this->category, categoryEditData($this->category, ['name' => 'Rede']))
        ->assertSessionHasNoErrors();

    expect(TicketCategory::where('name', 'Rede')->count())->toBe(2);
});

test('saving without changes records nothing', function () {
    patchCategory($this->admin, $this->category, categoryEditData($this->category))
        ->assertRedirect(route('admin.categories.edit', $this->category))
        ->assertSessionHas('info', 'Nenhuma alteração para salvar.');

    expect(AuditLog::count())->toBe(0);
});

test('a stale form is rejected as a conflict', function () {
    $stale = categoryEditData($this->category, ['name' => 'Versão antiga']);
    $this->category->update(['name' => 'Alterada por outra pessoa']);

    patchCategory($this->admin, $this->category, $stale)->assertSessionHasErrors('conflict');

    expect($this->category->fresh()->name)->toBe('Alterada por outra pessoa')
        ->and(AuditLog::count())->toBe(0);
});

test('fields outside the form are not changed', function () {
    $createdAt = $this->category->created_at->toIso8601String();

    patchCategory($this->admin, $this->category, categoryEditData($this->category, [
        'name' => 'Hardware Atualizado',
        'id' => 999999,
        'is_active' => false,
        'created_at' => '2000-01-01 00:00:00',
        'submission_token' => (string) Str::uuid(),
    ]))->assertSessionHasNoErrors();

    $fresh = $this->category->fresh();

    expect($fresh)->not->toBeNull()
        ->and(TicketCategory::find(999999))->toBeNull()
        ->and(TicketCategory::count())->toBe(1)
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->created_at->toIso8601String())->toBe($createdAt);
});

/* ---------- Validação ---------- */

test('invalid values are rejected without partial changes', function (array $overrides, string $field) {
    patchCategory($this->admin, $this->category, categoryEditData($this->category, ['description' => 'Parcial', ...$overrides]))
        ->assertSessionHasErrors($field);

    expect($this->category->fresh())
        ->description->toBe('Computadores e periféricos.')
        ->is_active->toBeTrue()
        ->and(AuditLog::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'blank name' => [['name' => '   '], 'name'],
    'name as array' => [['name' => ['x']], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'description too long' => [['description' => str_repeat('a', 1001)], 'description'],
    'description as array' => [['description' => ['x']], 'description'],
    'unknown status' => [['status' => 'archived'], 'status'],
    'missing status' => [['status' => ''], 'status'],
    'status as boolean' => [['status' => true], 'status'],
    'missing version' => [['version' => ''], 'version'],
]);

test('the limits accept values at the boundary', function () {
    patchCategory($this->admin, $this->category, categoryEditData($this->category, [
        'name' => str_repeat('a', 255),
        'description' => str_repeat('b', 1000),
    ]))->assertSessionHasNoErrors();

    expect(mb_strlen($this->category->fresh()->name))->toBe(255);
});

test('validation messages are in Portuguese', function () {
    patchCategory($this->admin, $this->category, categoryEditData($this->category, ['name' => '']))
        ->assertSessionHasErrors(['name' => 'O campo nome da categoria é obrigatório.']);
});

/* ---------- Situação e integridade histórica ---------- */

test('deactivation keeps the record and every historical reference', function () {
    $ticket = Ticket::factory()->create(['category_id' => $this->category->id]);
    $ticket->histories()->forceCreate([
        'event_type' => TicketEvent::Created,
        'new_values' => ['category' => ['id' => $this->category->id, 'label' => 'Hardware']],
        'created_at' => now(),
    ]);

    patchCategory($this->admin, $this->category, categoryEditData($this->category, ['status' => 'inactive']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Categoria Hardware inativada. Os chamados vinculados foram preservados.');

    expect($this->category->fresh())->not->toBeNull()
        ->and($this->category->fresh()->is_active)->toBeFalse()
        ->and($ticket->fresh()->category_id)->toBe($this->category->id)
        ->and($ticket->histories()->sole()->new_values['category']['label'])->toBe('Hardware');

    // A categoria continua consultável na listagem administrativa.
    $this->actingAs($this->admin)
        ->get('/admin/categories?status=inactive')
        ->assertInertia(fn (Assert $page) => $page->where('categories.data.0.id', $this->category->id));

    // E os detalhes do chamado continuam exibindo a categoria.
    $this->actingAs($this->admin)
        ->get("/tickets/{$ticket->ticket_number}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ticket.category', 'Hardware'));
});

test('an inactive category can no longer be used in new tickets', function () {
    patchCategory($this->admin, $this->category, categoryEditData($this->category, ['status' => 'inactive']));

    $requester = User::factory()->withRole('requester')->create();

    $this->actingAs($requester)
        ->get('/tickets/create')
        ->assertInertia(fn (Assert $page) => $page->where('categories', []));

    $this->actingAs($requester)
        ->post('/tickets', [
            'title' => 'Monitor sem imagem',
            'description' => 'O monitor não liga desde ontem.',
            'category_id' => $this->category->id,
            'priority' => 'medium',
            'submission_token' => (string) Str::uuid(),
        ])
        ->assertSessionHasErrors('category_id');

    expect(Ticket::count())->toBe(0);
});

test('renaming keeps tickets linked and their history readable', function () {
    $ticket = Ticket::factory()->create(['category_id' => $this->category->id]);
    $ticket->histories()->forceCreate([
        'event_type' => TicketEvent::Created,
        'new_values' => ['category' => ['id' => $this->category->id, 'label' => 'Hardware']],
        'created_at' => now(),
    ]);
    $ticketUpdatedAt = $ticket->fresh()->updated_at;
    $this->travel(5)->minutes();

    patchCategory($this->admin, $this->category, categoryEditData($this->category, ['name' => 'Equipamentos']));

    expect($ticket->fresh()->category_id)->toBe($this->category->id)
        ->and($ticket->fresh()->category->name)->toBe('Equipamentos')
        ->and($ticket->fresh()->updated_at->equalTo($ticketUpdatedAt))->toBeTrue()
        ->and($ticket->histories()->sole()->new_values['category']['label'])->toBe('Hardware');
});

test('reactivation is audited separately', function () {
    $inactive = TicketCategory::factory()->create(['name' => 'Legado', 'is_active' => false]);

    patchCategory($this->admin, $inactive, categoryEditData($inactive, ['status' => 'active']))
        ->assertSessionHas('success', 'Categoria Legado reativada.');

    expect($inactive->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::sole()->event_type)->toBe(AdminEvent::CategoryActivated);
});

test('editing one category does not change the others', function () {
    $other = TicketCategory::factory()->create(['name' => 'Rede']);
    $snapshot = $other->fresh()->getAttributes();

    patchCategory($this->admin, $this->category, categoryEditData($this->category, ['name' => 'Equipamentos', 'status' => 'inactive']));

    expect($other->fresh()->getAttributes())->toBe($snapshot);
});

/* ---------- Auditoria e atomicidade ---------- */

test('changes are audited with the operator and previous and new values', function () {
    patchCategory($this->admin, $this->category, categoryEditData($this->category, [
        'name' => 'Equipamentos',
        'status' => 'inactive',
    ]));

    $logs = AuditLog::orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->event_type)->toBe(AdminEvent::CategoryUpdated)
        ->and($logs[0]->user_id)->toBe($this->admin->id)
        ->and($logs[0]->auditable->is($this->category))->toBeTrue()
        ->and($logs[0]->created_at)->not->toBeNull()
        ->and($logs[0]->old_values)->toBe(['name' => 'Hardware'])
        ->and($logs[0]->new_values)->toBe(['name' => 'Equipamentos'])
        ->and($logs[1]->event_type)->toBe(AdminEvent::CategoryDeactivated)
        ->and($logs[1]->old_values)->toBe(['is_active' => true])
        ->and($logs[1]->new_values)->toBe(['is_active' => false]);
});

test('a failure while auditing leaves no partial change', function () {
    AuditLog::creating(fn () => throw new RuntimeException('falha simulada'));

    $this->withoutExceptionHandling();

    expect(fn () => patchCategory($this->admin, $this->category, categoryEditData($this->category, ['name' => 'Parcial', 'status' => 'inactive'])))
        ->toThrow(RuntimeException::class, 'falha simulada');

    expect($this->category->fresh())
        ->name->toBe('Hardware')
        ->is_active->toBeTrue();
});
