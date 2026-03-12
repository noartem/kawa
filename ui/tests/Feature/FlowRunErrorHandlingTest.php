<?php

namespace Tests\Feature;

use App\Jobs\ProcessFlowManagerEvent;
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

    public function test_flow_service_does_not_wait_for_runtime_graph_after_successful_start(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'container_id' => null,
            'image' => 'flow:dev',
        ]);

        $previousRun = $flow->runs()->create([
            'type' => 'development',
            'active' => false,
            'status' => 'stopped',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
            'code_snapshot' => $flow->code,
            'graph_snapshot' => [
                'nodes' => [
                    ['id' => 'previous.event', 'type' => 'event', 'label' => 'previous.event'],
                ],
                'edges' => [],
            ],
        ]);

        $client = $this->mock(FlowManagerClient::class);
        $client
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn([
                'ok' => true,
                'correlation_id' => 'corr-id',
            ]);

        $client
            ->shouldReceive('containerGraph')
            ->never();

        $service = app(FlowService::class);
        $result = $service->start($flow);

        $this->assertTrue($result['ok']);

        $run = $flow->runs()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('creating', $run->status);
        $this->assertSame($flow->code, $run->code_snapshot);
        $this->assertSame($previousRun->graph_snapshot, $run->graph_snapshot);
    }

    public function test_flow_service_marks_run_as_stopping_until_runtime_confirms_stop(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'container_id' => null,
            'image' => 'flow:dev',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'container_id' => 'container-id-1',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => [
                'nodes' => [],
                'edges' => [],
            ],
        ]);

        $this->mock(FlowManagerClient::class)
            ->shouldReceive('stopContainer')
            ->once()
            ->with('container-id-1');

        $service = app(FlowService::class);
        $result = $service->stop($flow);

        $this->assertTrue($result['ok']);

        $run->refresh();

        $this->assertSame('stopping', $run->status);
        $this->assertTrue($run->active);
        $this->assertNull($run->finished_at);

    }

    public function test_flow_service_passes_timezone_to_flow_manager_payload(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'container_id' => null,
            'image' => 'flow:dev',
            'timezone' => 'Europe/Berlin',
        ]);

        $this->mock(FlowManagerClient::class)
            ->shouldReceive('createContainer')
            ->once()
            ->withArgs(function (array $payload): bool {
                return ($payload['environment']['FLOW_TIMEZONE'] ?? null)
                        === 'Europe/Berlin'
                    && ($payload['labels']['kawaflow.timezone'] ?? null)
                        === 'Europe/Berlin';
            })
            ->andReturn([
                'ok' => true,
            ]);

        $service = app(FlowService::class);
        $result = $service->start($flow);

        $this->assertTrue($result['ok']);
    }

    public function test_container_created_event_pulls_graph_from_runtime_async(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'container_id' => 'container-id-1',
            'code_updated_at' => now(),
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'creating',
            'started_at' => now(),
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $client = $this->mock(FlowManagerClient::class);
        $client
            ->shouldReceive('containerGraph')
            ->once()
            ->with('container-id-1')
            ->andReturn([
                'events' => [
                    [
                        'id' => 'CronEvent',
                        'source_line' => 6,
                        'source_kind' => 'import',
                        'source_module' => 'app.events',
                    ],
                ],
                'actors' => [
                    [
                        'id' => 'StarterActor',
                        'receives' => ['CronEvent'],
                        'sends' => ['PreparedEvent'],
                        'source_line' => 16,
                        'source_kind' => 'main',
                    ],
                ],
            ]);

        $job = new ProcessFlowManagerEvent('container_created', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-1',
        ]);
        $job->handle();

        $flow->refresh();
        $run->refresh();

        $this->assertSame('created', $run->status);
        $this->assertSame('container-id-1', $run->container_id);
        $this->assertNotEmpty($run->graph_snapshot['nodes'] ?? []);
        $this->assertNotEmpty($run->graph_snapshot['edges'] ?? []);
        $this->assertArrayNotHasKey('graph', $flow->getAttributes());
        $this->assertArrayNotHasKey('graph_generated_at', $flow->getAttributes());
        $cronEventNode = collect($run->graph_snapshot['nodes'])->firstWhere('id', 'CronEvent');
        $this->assertSame(6, $cronEventNode['source_line'] ?? null);
        $this->assertSame('import', $cronEventNode['source_kind'] ?? null);
        $this->assertSame('app.events', $cronEventNode['source_module'] ?? null);
        $this->assertSame(16, $run->graph_snapshot['nodes'][1]['source_line'] ?? null);
        $this->assertSame('main', $run->graph_snapshot['nodes'][1]['source_kind'] ?? null);
    }

    public function test_container_crashed_event_does_not_override_intentional_stop(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => false,
            'status' => 'stopped',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'container_id' => 'container-id-1',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManagerEvent('container_crashed', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-1',
            'exit_code' => 137,
        ]);
        $job->handle();

        $run->refresh();

        $this->assertSame('stopped', $run->status);
        $this->assertFalse($run->active);
    }

    public function test_status_changed_event_can_resolve_run_by_flow_run_id_before_container_binding(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'created',
            'started_at' => now()->subMinute(),
            'container_id' => null,
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManagerEvent('status_changed', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-2',
            'old_state' => 'created',
            'new_state' => 'running',
        ]);
        $job->handle();

        $run->refresh();

        $this->assertSame('running', $run->status);
        $this->assertTrue($run->active);
    }
}
