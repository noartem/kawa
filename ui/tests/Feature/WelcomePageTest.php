<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('guests see the welcome page', function () {
    Config::set('fortify.features', [Features::registration()]);

    $this->get('/')
        ->assertSuccessful()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Welcome')
                ->where('canRegister', true),
        );
});

test('guests do not see the register action when registration is disabled', function () {
    Config::set('fortify.features', []);

    $this->get('/')
        ->assertSuccessful()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Welcome')
                ->where('canRegister', false),
        );
});

test('authenticated users are redirected to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});
