<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\User;
use App\Services\FlowManagerClient;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRunErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_action_flashes_specific_error_message_from_service(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne();

        $this->mock(FlowManagerClient::class)
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn([
                'ok' => false,
                'message' => 'No such image: flow:dev',
                'error_type' => 'image_not_found',
                'details' => [
                    'data' => [
                        'image' => 'flow:dev',
                    ],
                ],
            ]);

        $response = $this
            ->actingAs($user)
            ->post(route('flows.run', $flow));

        $response
            ->assertRedirect(route('flows.show', $flow))
            ->assertSessionHas(
                'error',
                __('flows.run.image_not_found', ['image' => 'flow:dev']),
            );
    }

    public function test_flow_service_marks_run_as_error_when_flow_manager_returns_image_not_found(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'container_id' => null,
            'image' => 'flow:dev',
        ]);

        $this->mock(FlowManagerClient::class)
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn([
                'ok' => false,
                'message' => 'No such image: flow:dev',
                'error_type' => 'image_not_found',
                'details' => [
                    'data' => [
                        'image' => 'flow:dev',
                    ],
                ],
                'correlation_id' => 'corr-id',
            ]);

        $service = app(FlowService::class);

        $result = $service->start($flow);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            __('flows.run.image_not_found', ['image' => 'flow:dev']),
            $result['message'],
        );

        $run = $flow->runs()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame('error', $run->status);
        $this->assertFalse($run->active);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('image_not_found', $run->meta['error_type'] ?? null);

        $logEntry = $run->logs()->latest('id')->first();

        $this->assertNotNull($logEntry);
        $this->assertSame('error', $logEntry->level);
        $this->assertSame($result['message'], $logEntry->message);
    }

    public function test_flow_service_updates_flow_graph_from_runtime_after_successful_start(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'container_id' => null,
            'image' => 'flow:dev',
            'graph' => ['nodes' => [], 'edges' => []],
        ]);

        $runtimeGraph = [
            'events' => [
                ['id' => 'cron_event', 'name' => 'CronEvent'],
            ],
            'actors' => [
                ['id' => 'prepare_actor', 'name' => 'PrepareMorningMessage'],
                ['id' => 'morning_actor', 'name' => 'MorningActor'],
            ],
        ];

        $client = $this->mock(FlowManagerClient::class);
        $client
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn([
                'ok' => true,
                'data' => [
                    'container_id' => 'container-id-1',
                ],
            ]);

        $client
            ->shouldReceive('containerGraph')
            ->once()
            ->with('container-id-1')
            ->andReturn($runtimeGraph);

        $service = app(FlowService::class);
        $result = $service->start($flow);

        $this->assertTrue($result['ok']);

        $flow->refresh();
        $this->assertSame($runtimeGraph, $flow->graph);
    }
}
