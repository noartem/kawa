<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('keeps the sidebar collapsed by default without a saved cookie', function () {
    $user = User::factory()->createOne();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarOpen', false)
        );
});

it('keeps the sidebar expanded when the saved cookie is true', function () {
    $user = User::factory()->createOne();

    $this->actingAs($user)
        ->withUnencryptedCookie('sidebar_state', 'true')
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarOpen', true)
        );
});
