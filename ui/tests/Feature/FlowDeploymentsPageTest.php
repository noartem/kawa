<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FlowDeploymentsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployments_page_shows_paginated_deployment_list(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne();

        FlowRun::factory()->count(17)->forFlow($flow)->sequence(
            fn ($sequence) => [
                'created_at' => now()->subMinutes(17 - $sequence->index),
                'updated_at' => now()->subMinutes(17 - $sequence->index),
            ],
        )->create();

        $response = $this->actingAs($user)->get(route('flows.deployments', [
            'flow' => $flow,
            'page' => 2,
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Deployments')
            ->where('flow.id', $flow->id)
            ->where('deployments.current_page', 2)
            ->where('deployments.last_page', 2)
            ->has('deployments.data', 2)
        );
    }
}
