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
            ->where('sorting.column', 'created_at')
            ->where('sorting.direction', 'desc')
            ->where('deployments.current_page', 2)
            ->where('deployments.last_page', 2)
            ->has('deployments.data', 2)
        );
    }

    public function test_deployments_page_applies_status_type_and_search_filters(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne();

        FlowRun::factory()->forFlow($flow)->createOne([
            'status' => 'failed',
            'type' => 'development',
            'container_id' => 'container-old',
        ]);

        $targetRun = FlowRun::factory()->forFlow($flow)->createOne([
            'status' => 'success',
            'type' => 'production',
            'container_id' => 'container-target',
        ]);

        FlowRun::factory()->forFlow($flow)->createOne([
            'status' => 'success',
            'type' => 'development',
            'container_id' => 'container-other',
        ]);

        $response = $this->actingAs($user)->get(route('flows.deployments', [
            'flow' => $flow,
            'status' => 'success',
            'type' => 'production',
            'search' => 'target',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Deployments')
            ->where('filters.status', 'success')
            ->where('filters.type', 'production')
            ->where('filters.search', 'target')
            ->has('deployments.data', 1)
            ->where('deployments.data.0.id', $targetRun->id)
        );
    }

    public function test_deployments_page_applies_requested_sorting(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne();

        $latestRun = FlowRun::factory()->forFlow($flow)->createOne([
            'started_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $middleRun = FlowRun::factory()->forFlow($flow)->createOne([
            'started_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        $oldestRun = FlowRun::factory()->forFlow($flow)->createOne([
            'started_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($user)->get(route('flows.deployments', [
            'flow' => $flow,
            'sort' => 'started_at',
            'direction' => 'asc',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Deployments')
            ->where('sorting.column', 'started_at')
            ->where('sorting.direction', 'asc')
            ->where('deployments.data.0.id', $oldestRun->id)
            ->where('deployments.data.1.id', $middleRun->id)
            ->where('deployments.data.2.id', $latestRun->id)
        );
    }
}
