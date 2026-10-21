<?php

use App\Models\User;

it('redirects guests from the home page to the login', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('redirects authenticated users from the home page to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});
