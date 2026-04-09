<?php

namespace Tests\Feature;

use App\Jobs\ProcessFlowManager;
use App\Mail\FlowRuntimeEmail;
use App\Models\Flow;
use App\Models\FlowStorage;
use App\Models\User;
use App\Services\FlowManagerClient;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
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
                'events' => [
                    ['id' => 'previous.event'],
                ],
                'actors' => [
                    ['id' => 'previous.actor'],
                ],
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
            ->withArgs(function (array $payload): bool {
                return ($payload['events'] ?? null) === [['id' => 'previous.event']]
                    && ($payload['actors'] ?? null) === [['id' => 'previous.actor']];
            })
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

    public function test_flow_service_binds_created_container_from_flow_manager_response(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'container_id' => 'stale-container-id',
            'image' => 'flow:dev',
        ]);

        $this->mock(FlowManagerClient::class)
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn([
                'ok' => true,
                'data' => [
                    'container_id' => 'container-id-2',
                    'status' => 'created',
                ],
            ]);

        $service = app(FlowService::class);
        $result = $service->start($flow);

        $this->assertTrue($result['ok']);

        $flow->refresh();
        $run = $flow->runs()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame('created', $run->status);
        $this->assertSame('container-id-2', $run->container_id);
        $this->assertSame('container-id-2', $flow->container_id);
    }

    public function test_mark_lock_ready_creates_a_runtime_container_for_production(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'deploying',
            'container_id' => null,
            'image' => 'flow:dev',
            'timezone' => 'Europe/Berlin',
            'code' => 'print("latest")',
        ]);

        $run = $flow->runs()->create([
            'type' => 'production',
            'active' => true,
            'status' => 'locked',
            'code_snapshot' => 'print("snapshot")',
            'graph_snapshot' => [
                'events' => [['id' => 'Webhook.by("orders.created")']],
                'actors' => [['id' => 'HandleWebhook']],
                'nodes' => [],
                'edges' => [],
            ],
            'lock' => 'lock-data',
            'started_at' => now(),
        ]);

        $this->mock(FlowManagerClient::class)
            ->shouldReceive('createContainer')
            ->once()
            ->withArgs(function (array $payload) use ($flow, $run): bool {
                return ($payload['flow_id'] ?? null) === $flow->id
                    && ($payload['flow_run_id'] ?? null) === $run->id
                    && ($payload['environment']['FLOW_PATH'] ?? null) === '/flow/flow.py'
                    && ($payload['environment']['FLOW_TIMEZONE'] ?? null) === 'Europe/Berlin'
                    && ($payload['labels']['kawaflow.deployment_type'] ?? null) === 'production'
                    && ($payload['command'] ?? null) === ['uv', 'run', '/flow/main.py']
                    && ($payload['volumes'] ?? []) !== [];
            })
            ->andReturn([
                'ok' => true,
                'data' => [
                    'container_id' => 'production-container-id',
                ],
            ]);

        $service = app(FlowService::class);
        $service->markLockReady($flow, $run);

        $flow->refresh();
        $run->refresh();

        $this->assertSame('created', $run->status);
        $this->assertSame('production-container-id', $run->container_id);
        $this->assertSame('production-container-id', $flow->container_id);

        $deploymentRoot = storage_path(sprintf('app/flows/%d/%s/%d', $flow->id, $run->type, $run->id));

        $this->assertFileExists($deploymentRoot.'/flow.py');
        $this->assertFileExists($deploymentRoot.'/main.py');
        $this->assertFileExists($deploymentRoot.'/uv.lock');
        $this->assertSame('print("snapshot")', file_get_contents($deploymentRoot.'/flow.py'));
        $mainScript = (string) file_get_contents($deploymentRoot.'/main.py');
        $this->assertStringContainsString('from kawa.runtime.app import main', $mainScript);
        $this->assertStringContainsString("if __name__ == '__main__':", $mainScript);
    }

    public function test_deploy_production_generates_lock_from_runtime_entrypoint_script(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'image' => 'flow:dev',
            'code' => <<<'PY'
# /// script
# dependencies = [
#   "httpx>=0.27",
# ]
# ///

print("latest")
PY,
        ]);

        $this->mock(FlowManagerClient::class)
            ->shouldReceive('generateLock')
            ->once()
            ->withArgs(function (array $payload): bool {
                $code = (string) ($payload['code'] ?? '');

                return str_contains($code, '# /// script')
                    && str_contains($code, '#   "httpx>=0.27",')
                    && str_contains($code, 'from kawa.runtime.app import main')
                    && ! str_contains($code, 'print("latest")');
            })
            ->andReturn([
                'ok' => false,
                'message' => 'lock failed',
            ]);

        $result = app(FlowService::class)->deployProduction($flow);

        $this->assertFalse($result['ok']);
    }

    public function test_deploy_production_returns_failure_when_runtime_container_creation_fails(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'container_id' => null,
            'image' => 'flow:dev',
        ]);

        $client = $this->mock(FlowManagerClient::class);
        $client
            ->shouldReceive('generateLock')
            ->once()
            ->andReturn([
                'ok' => true,
                'data' => [
                    'lock' => 'lock-data',
                ],
            ]);

        $client
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn([
                'ok' => false,
                'message' => 'Runtime unavailable',
            ]);

        $result = app(FlowService::class)->deployProduction($flow);

        $this->assertFalse($result['ok']);
        $this->assertSame('Runtime unavailable', $result['message']);

        $flow->refresh();
        $run = $flow->runs()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame('error', $run->status);
        $this->assertFalse($run->active);
        $this->assertSame('error', $flow->status);
        $this->assertNotNull($flow->last_finished_at);
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

    public function test_flow_service_resolves_runtime_container_before_stopping(): void
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
            'status' => 'creating',
            'started_at' => now()->subSeconds(10),
            'container_id' => null,
            'code_snapshot' => $flow->code,
            'graph_snapshot' => [
                'nodes' => [],
                'edges' => [],
            ],
        ]);

        $expectedName = sprintf('flow-%d-run-%d', $flow->id, $run->id);

        $client = $this->mock(FlowManagerClient::class);

        $client
            ->shouldReceive('listContainers')
            ->once()
            ->andReturn([
                'ok' => true,
                'data' => [
                    'containers' => [
                        [
                            'id' => 'container-id-2',
                            'name' => $expectedName,
                            'status' => 'running',
                            'image' => 'flow:dev',
                            'created' => now()->toIso8601String(),
                            'socket_path' => '/tmp/kawaflow.sock',
                            'ports' => [],
                        ],
                    ],
                ],
            ]);

        $client
            ->shouldReceive('stopContainer')
            ->once()
            ->with('container-id-2');

        $service = app(FlowService::class);
        $result = $service->stop($flow);

        $this->assertTrue($result['ok']);

        $run->refresh();
        $flow->refresh();

        $this->assertSame('stopping', $run->status);
        $this->assertSame('container-id-2', $run->container_id);
        $this->assertSame('container-id-2', $flow->container_id);
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

    public function test_flow_service_passes_storage_to_flow_manager_payload(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'container_id' => null,
            'image' => 'flow:dev',
        ]);

        FlowStorage::factory()->forFlow($flow)->createOne([
            'environment' => 'development',
            'content' => [
                'settings' => [
                    'profile' => [
                        'language' => 'en',
                    ],
                ],
            ],
        ]);

        $this->mock(FlowManagerClient::class)
            ->shouldReceive('createContainer')
            ->once()
            ->withArgs(function (array $payload): bool {
                return ($payload['storage']['settings']['profile']['language'] ?? null) === 'en';
            })
            ->andReturn([
                'ok' => true,
            ]);

        $result = app(FlowService::class)->start($flow);

        $this->assertTrue($result['ok']);
    }

    public function test_storage_update_persists_json_when_same_type_run_is_inactive(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne();

        $flow->runs()->create([
            'type' => 'production',
            'active' => true,
            'status' => 'running',
            'container_id' => 'prod-container',
            'started_at' => now()->subMinute(),
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $response = $this
            ->actingAs($user)
            ->put(route('flows.storage.update', $flow), [
                'environment' => 'development',
                'content' => json_encode([
                    'users' => [
                        ['name' => 'Ada'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ]);

        $response
            ->assertRedirect(route('flows.show', $flow))
            ->assertSessionHas('success', __('flows.storage.updated'));

        $storage = FlowStorage::query()
            ->where('flow_id', $flow->id)
            ->where('environment', 'development')
            ->first();

        $this->assertNotNull($storage);
        $this->assertSame('Ada', $storage->content['users'][0]['name'] ?? null);
    }

    public function test_storage_update_rejects_active_run_of_same_type(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne();

        $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'container_id' => 'dev-container',
            'started_at' => now()->subMinute(),
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $response = $this
            ->actingAs($user)
            ->put(route('flows.storage.update', $flow), [
                'environment' => 'development',
                'content' => json_encode([
                    'settings' => [
                        'profile' => [
                            'language' => 'ru',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ]);

        $response
            ->assertRedirect(route('flows.show', $flow))
            ->assertSessionHas('error', __('flows.storage.error_active'));

        $this->assertNull(
            FlowStorage::query()
                ->where('flow_id', $flow->id)
                ->where('environment', 'development')
                ->first(),
        );
    }

    public function test_container_created_event_uses_graph_from_event_payload(): void
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

        $job = new ProcessFlowManager('container_created', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-1',
            'events' => [
                [
                    'id' => 'Cron',
                    'source_line' => 6,
                    'source_kind' => 'import',
                    'source_module' => 'app.events',
                ],
            ],
            'actors' => [
                [
                    'id' => 'StarterActor',
                    'receives' => ['Cron'],
                    'sends' => ['Prepared'],
                    'source_line' => 16,
                    'source_kind' => 'main',
                ],
            ],
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
        $cronEventNode = collect($run->graph_snapshot['nodes'])->firstWhere('id', 'Cron');
        $this->assertSame(6, $cronEventNode['source_line'] ?? null);
        $this->assertSame('import', $cronEventNode['source_kind'] ?? null);
        $this->assertSame('app.events', $cronEventNode['source_module'] ?? null);
        $this->assertSame(16, $run->graph_snapshot['nodes'][1]['source_line'] ?? null);
        $this->assertSame('main', $run->graph_snapshot['nodes'][1]['source_kind'] ?? null);
    }

    public function test_flow_storage_updated_event_persists_storage_for_environment(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne();

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'started_at' => now(),
            'container_id' => 'container-id-9',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManager('flow_storage_updated', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'environment' => 'development',
            'storage' => [
                'settings' => [
                    'profile' => [
                        'language' => 'ru',
                    ],
                ],
            ],
        ]);
        $job->handle();

        $storage = FlowStorage::query()
            ->where('flow_id', $flow->id)
            ->where('environment', 'development')
            ->first();

        $this->assertNotNull($storage);
        $this->assertSame('ru', $storage->content['settings']['profile']['language'] ?? null);
    }

    public function test_runtime_graph_updated_event_refreshes_graph_for_current_run(): void
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
            'status' => 'running',
            'started_at' => now(),
            'container_id' => null,
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManager('runtime_graph_updated', [
            'flow_id' => (string) $flow->id,
            'flow_run_id' => (string) $run->id,
            'container_id' => 'container-id-1',
            'events' => [
                [
                    'id' => 'Cron',
                    'source_line' => 6,
                ],
            ],
            'actors' => [
                [
                    'id' => 'StarterActor',
                    'receives' => ['Cron'],
                    'sends' => ['Prepared'],
                    'source_line' => 16,
                ],
            ],
        ]);
        $job->handle();

        $run->refresh();

        $this->assertNotEmpty($run->graph_snapshot['nodes'] ?? []);
        $this->assertNotEmpty($run->graph_snapshot['edges'] ?? []);
        $this->assertSame('Cron', $run->graph_snapshot['nodes'][0]['id'] ?? null);
    }

    public function test_send_email_runtime_event_uses_explicit_recipient(): void
    {
        Mail::fake();

        /** @var User $user */
        $user = User::factory()->createOne(['email' => 'owner@example.com']);
        $flow = Flow::factory()->forUser($user)->createOne([
            'name' => 'Approval Flow',
            'container_id' => 'container-id-5',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'container_id' => 'container-id-5',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManager('flow_runtime_event', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-5',
            'kind' => 'event_dispatched',
            'event' => 'SendEmail',
            'payload' => [
                'message' => 'Approval requested',
                'recipient' => 'recipient@example.com',
                'subject' => 'Manual approval needed',
            ],
        ]);
        $job->handle();

        Mail::assertSent(FlowRuntimeEmail::class, function (FlowRuntimeEmail $mail): bool {
            $mail->assertTo('recipient@example.com');
            $mail->assertHasSubject('Manual approval needed');
            $mail->assertSeeInText('Approval requested');

            return true;
        });

        $logs = $run->logs()->orderBy('id')->get();

        $this->assertSame([
            'Event: flow_runtime_event',
            'Email sent.',
        ], $logs->pluck('message')->all());
        $this->assertSame([
            'message_redacted' => true,
            'recipient_count' => 1,
            'subject_present' => true,
        ], $logs[0]->context['payload'] ?? null);
    }

    public function test_send_email_runtime_event_uses_recipient_list(): void
    {
        Mail::fake();

        /** @var User $user */
        $user = User::factory()->createOne(['email' => 'owner@example.com']);
        $flow = Flow::factory()->forUser($user)->createOne([
            'name' => 'Broadcast Flow',
            'container_id' => 'container-id-5b',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'container_id' => 'container-id-5b',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManager('flow_runtime_event', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-5b',
            'kind' => 'event_dispatched',
            'event' => 'SendEmail',
            'payload' => [
                'message' => 'Broadcast update',
                'recipient' => [
                    ' first@example.com ',
                    'second@example.com',
                    'first@example.com',
                    '',
                    123,
                ],
                'subject' => 'Broadcast notification',
            ],
        ]);
        $job->handle();

        Mail::assertSent(FlowRuntimeEmail::class, function (FlowRuntimeEmail $mail): bool {
            $mail->assertHasSubject('Broadcast notification');
            $mail->assertSeeInText('Broadcast update');

            return $mail->hasTo('first@example.com')
                && $mail->hasTo('second@example.com');
        });

        $logs = $run->logs()->orderBy('id')->get();

        $this->assertSame([
            'message_redacted' => true,
            'recipient_count' => 2,
            'subject_present' => true,
        ], $logs[0]->context['payload'] ?? null);
    }

    public function test_send_email_runtime_event_falls_back_to_flow_owner(): void
    {
        Mail::fake();

        /** @var User $user */
        $user = User::factory()->createOne(['email' => 'owner@example.com']);
        $flow = Flow::factory()->forUser($user)->createOne([
            'name' => 'Fallback Flow',
            'container_id' => 'container-id-6',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'container_id' => 'container-id-6',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManager('flow_runtime_event', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-6',
            'kind' => 'event_dispatched',
            'event' => 'SendEmail',
            'payload' => [
                'message' => 'Fallback recipient',
                'subject' => '   ',
            ],
        ]);
        $job->handle();

        Mail::assertSent(FlowRuntimeEmail::class, function (FlowRuntimeEmail $mail): bool {
            $mail->assertTo('owner@example.com');
            $mail->assertHasSubject('Flow "Fallback Flow" notification');
            $mail->assertSeeInText('Fallback recipient');

            return true;
        });
    }

    public function test_send_email_runtime_event_logs_warning_when_recipient_is_missing(): void
    {
        Mail::fake();

        /** @var User $user */
        $user = User::factory()->createOne(['email' => '']);
        $flow = Flow::factory()->forUser($user)->createOne([
            'container_id' => 'container-id-7',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'container_id' => 'container-id-7',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManager('flow_runtime_event', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-7',
            'kind' => 'event_dispatched',
            'event' => 'SendEmail',
            'payload' => [
                'message' => 'No recipient available',
            ],
        ]);
        $job->handle();

        Mail::assertNothingSent();

        $logs = $run->logs()->orderBy('id')->get(['level', 'message']);

        $this->assertSame('info', $logs[0]->level);
        $this->assertSame('Event: flow_runtime_event', $logs[0]->message);
        $this->assertSame('warning', $logs[1]->level);
        $this->assertSame('SendEmail skipped: recipient not resolved.', $logs[1]->message);
    }

    public function test_send_email_runtime_event_logs_error_when_mail_send_fails(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->with(['recipient@example.com'])
            ->andReturnSelf();

        Mail::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('SMTP unavailable'));

        /** @var User $user */
        $user = User::factory()->createOne(['email' => 'owner@example.com']);
        $flow = Flow::factory()->forUser($user)->createOne([
            'container_id' => 'container-id-8',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'container_id' => 'container-id-8',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManager('flow_runtime_event', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-8',
            'kind' => 'event_dispatched',
            'event' => 'SendEmail',
            'payload' => [
                'message' => 'Send failure',
                'recipient' => 'recipient@example.com',
            ],
        ]);
        $job->handle();

        $logs = $run->logs()->orderBy('id')->get(['level', 'message', 'context']);

        $this->assertSame('error', $logs[1]->level);
        $this->assertSame('SendEmail failed during delivery.', $logs[1]->message);
        $this->assertSame('SMTP unavailable', $logs[1]->context['error'] ?? null);
    }

    public function test_malformed_send_email_runtime_event_still_redacts_log_context(): void
    {
        Mail::fake();

        /** @var User $user */
        $user = User::factory()->createOne(['email' => 'owner@example.com']);
        $flow = Flow::factory()->forUser($user)->createOne([
            'container_id' => 'container-id-9',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'container_id' => 'container-id-9',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManager('flow_runtime_event', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-9',
            'kind' => 'event_dispatched',
            'event' => 'SendEmail',
            'payload' => [
                'message' => ['secret' => 'Approval requested'],
                'recipient' => 'recipient@example.com',
            ],
        ]);
        $job->handle();

        Mail::assertNothingSent();

        $logs = $run->logs()->orderBy('id')->get();

        $this->assertCount(1, $logs);
        $this->assertSame([
            'message_redacted' => true,
            'recipient_count' => 1,
            'subject_present' => false,
        ], $logs[0]->context['payload'] ?? null);
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

        $job = new ProcessFlowManager('container_crashed', [
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

    public function test_container_crashed_event_marks_run_inactive(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'container_id' => 'container-id-2',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'container_id' => 'container-id-2',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManager('container_crashed', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-2',
            'exit_code' => 255,
        ]);
        $job->handle();

        $flow->refresh();
        $run->refresh();

        $this->assertSame('error', $run->status);
        $this->assertFalse($run->active);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($flow->container_id);
    }

    public function test_flow_service_normalizes_finished_active_run_to_stopped(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'status' => 'draft',
            'container_id' => 'container-id-4',
        ]);

        $finishedAt = now()->subMinute();

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'error',
            'started_at' => now()->subMinutes(2),
            'finished_at' => $finishedAt,
            'container_id' => 'container-id-4',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $this->mock(FlowManagerClient::class)
            ->shouldNotReceive('stopContainer');

        $result = app(FlowService::class)->stop($flow);

        $this->assertTrue($result['ok']);

        $flow->refresh();
        $run->refresh();

        $this->assertSame('stopped', $run->status);
        $this->assertFalse($run->active);
        $this->assertSame(
            $finishedAt->toDateTimeString(),
            $run->finished_at?->toDateTimeString(),
        );
        $this->assertNull($flow->container_id);
    }

    public function test_stopped_status_event_clears_matching_flow_container_binding(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'container_id' => 'container-id-3',
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'container_id' => 'container-id-3',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => ['nodes' => [], 'edges' => []],
        ]);

        $job = new ProcessFlowManager('status_changed', [
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'container_id' => 'container-id-3',
            'old_state' => 'running',
            'new_state' => 'exited',
        ]);
        $job->handle();

        $flow->refresh();
        $run->refresh();

        $this->assertSame('stopped', $run->status);
        $this->assertNull($flow->container_id);
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

        $job = new ProcessFlowManager('status_changed', [
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
