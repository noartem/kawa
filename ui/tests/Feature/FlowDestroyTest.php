<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowRun;
use App\Models\User;
use App\Services\FlowManagerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_flow_without_password_even_after_production_deploy(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne();

        FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'production',
            'active' => false,
            'status' => 'stopped',
        ]);

        $this->actingAs($user)
            ->delete(route('flows.destroy', $flow))
            ->assertRedirect(route('flows.index'))
            ->assertSessionHas('success', __('flows.deleted'));

        $this->assertDatabaseMissing('flows', [
            'id' => $flow->id,
        ]);
    }

    public function test_it_cannot_delete_flow_with_active_deployments(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne();

        FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
        ]);

        $this->actingAs($user)
            ->delete(route('flows.destroy', $flow))
            ->assertRedirect(route('flows.show', $flow))
            ->assertSessionHas('error', __('flows.delete.error_active'));

        $this->assertDatabaseHas('flows', [
            'id' => $flow->id,
        ]);
    }

    public function test_it_deletes_runtime_containers_for_inactive_runs(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne();

        FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'active' => false,
            'status' => 'stopped',
            'container_id' => 'container-1',
        ]);

        FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'active' => false,
            'status' => 'stopped',
            'container_id' => 'container-2',
        ]);

        $client = $this->mock(FlowManagerClient::class);
        $client->shouldReceive('deleteContainer')->once()->with('container-1')->andReturn([
            'ok' => true,
        ]);
        $client->shouldReceive('deleteContainer')->once()->with('container-2')->andReturn([
            'ok' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('flows.destroy', $flow))
            ->assertRedirect(route('flows.index'))
            ->assertSessionHas('success', __('flows.deleted'));

        $this->assertDatabaseMissing('flows', [
            'id' => $flow->id,
        ]);
    }
}
