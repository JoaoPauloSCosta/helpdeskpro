<?php

use App\Models\Department;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->department = Department::factory()->create(['name' => 'Financeiro']);
});

function registrationData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'department_id' => test()->department->id,
        'password' => 'password',
        'password_confirmation' => 'password',
    ], $overrides);
}

function ticketData(array $overrides = []): array
{
    return array_merge([
        'title' => 'Impressora parada',
        'description' => 'Não imprime.',
        'category_id' => TicketCategory::factory()->create()->id,
        'priority' => 'medium',
        'submission_token' => (string) Str::uuid(),
    ], $overrides);
}

test('registration screen can be rendered', function () {
    $this->get('/register')->assertStatus(200);
});

test('registration screen lists only active departments in alphabetical order', function () {
    Department::factory()->create(['name' => 'Almoxarifado']);
    Department::factory()->create(['name' => 'Arquivo morto', 'is_active' => false]);

    $this->get('/register')->assertInertia(fn (Assert $page) => $page
        ->component('Auth/Register')
        ->has('departments', 2)
        ->where('departments.0.name', 'Almoxarifado')
        ->where('departments.1.name', 'Financeiro')
        ->has('departments.0', fn (Assert $department) => $department->hasAll(['id', 'name'])));
});

test('registration screen has no departments to offer when none is active', function () {
    Department::query()->update(['is_active' => false]);

    $this->get('/register')->assertInertia(fn (Assert $page) => $page->has('departments', 0));
});

test('new users can register', function () {
    $response = $this->post('/register', registrationData());

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('the selected department is saved on the user', function () {
    $this->post('/register', registrationData());

    expect(User::where('email', 'test@example.com')->firstOrFail()->department_id)->toBe($this->department->id);
});

test('new users are registered as requesters', function () {
    $this->post('/register', registrationData());

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->role->slug)->toBe('requester')
        ->and($user->is_active)->toBeTrue();
});

test('registration requires a department', function () {
    $this->post('/register', registrationData(['department_id' => '']))
        ->assertSessionHasErrors(['department_id' => 'Selecione seu departamento.']);

    $this->assertGuest();
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('registration rejects a department that does not exist', function () {
    $this->post('/register', registrationData(['department_id' => 999999]))
        ->assertSessionHasErrors('department_id');

    $this->assertGuest();
});

test('registration rejects an inactive department', function () {
    $inactive = Department::factory()->create(['is_active' => false]);

    $this->post('/register', registrationData(['department_id' => $inactive->id]))
        ->assertSessionHasErrors('department_id');

    $this->assertGuest();
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('registration rejects a malformed department', function () {
    $this->post('/register', registrationData(['department_id' => ['1']]))->assertSessionHasErrors('department_id');
    $this->post('/register', registrationData(['department_id' => 'abc']))->assertSessionHasErrors('department_id');
});

test('registration is refused when there is no active department', function () {
    Department::query()->update(['is_active' => false]);

    $this->post('/register', registrationData())->assertSessionHasErrors('department_id');

    $this->assertGuest();
});

test('registration ignores attempts to choose a role or privileges', function () {
    $this->post('/register', registrationData([
        'role_id' => Role::where('slug', 'admin')->value('id'),
        'role' => 'admin',
        'is_active' => false,
        'permissions' => ['users.manage'],
    ]));

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->role->slug)->toBe('requester')
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasPermission('users.manage'))->toBeFalse();
});

test('a user registered with a department can open a ticket', function () {
    $this->post('/register', registrationData());

    // Recarrega o usuário: a instância criada no cadastro ainda não traz o default `is_active` do banco.
    $this->actingAs(User::where('email', 'test@example.com')->firstOrFail())
        ->post(route('tickets.store'), ticketData())
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    expect(Ticket::firstOrFail()->department_id)->toBe($this->department->id);
});

test('legacy users without a department keep working but cannot open tickets', function () {
    $legacy = User::factory()->withRole('requester')->create(['department_id' => null]);

    $this->actingAs($legacy)->get(route('dashboard'))->assertOk();

    $this->actingAs($legacy)->post(route('tickets.store'), ticketData())->assertSessionHasErrors('department');

    expect($legacy->fresh()->department_id)->toBeNull()
        ->and(Ticket::count())->toBe(0);
});
