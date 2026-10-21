<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});

test('guests are redirected to login when opening the profile page', function () {
    $this->get('/profile')->assertRedirect('/login');
});

test('profile page exposes only the authenticated user without sensitive fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/profile')
        ->assertInertia(fn ($page) => $page
            ->component('Profile/Edit')
            ->where('auth.user.id', $user->id)
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token'));
});

test('profile update rejects an invalid email', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/profile')
        ->patch('/profile', ['name' => 'Teste', 'email' => 'nao-e-email'])
        ->assertSessionHasErrors('email')
        ->assertRedirect('/profile');
});

test('profile update rejects an email already used by another account', function () {
    $other = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/profile')
        ->patch('/profile', ['name' => 'Teste', 'email' => $other->email])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->email)->not->toBe($other->email);
});

test('profile update requires a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', ['name' => '', 'email' => $user->email])
        ->assertSessionHasErrors('name');
});

test('profile update ignores fields outside the form and never touches other accounts', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['name' => 'Outra Pessoa']);
    $originalPassword = $user->password;

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => 'Novo Nome',
            'email' => $user->email,
            'id' => $other->id,
            'user_id' => $other->id,
            'password' => 'hacked-password-1',
            'role_id' => 1,
            'department_id' => 1,
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Novo Nome')
        ->and($user->fresh()->password)->toBe($originalPassword)
        ->and($other->fresh()->name)->toBe('Outra Pessoa');
});

test('password can be changed from the profile page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'nova-senha-123',
            'password_confirmation' => 'nova-senha-123',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    expect(Hash::check('nova-senha-123', $user->fresh()->password))->toBeTrue();
});

test('password change fails with wrong current password or mismatched confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => 'errada',
        'password' => 'nova-senha-123',
        'password_confirmation' => 'nova-senha-123',
    ])->assertSessionHasErrors('current_password');

    $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => 'password',
        'password' => 'nova-senha-123',
        'password_confirmation' => 'diferente',
    ])->assertSessionHasErrors('password');
});
