<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowHistory;
use App\Models\FlowLog;
use App\Models\FlowRun;
use App\Models\FlowStorage;
use App\Models\User;
use App\Services\FlowWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FlowDeploymentsPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_payload_includes_deployment_details_with_snapshots_and_logs(): void
    {
        $this->travelTo(now()->startOfSecond());

        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'code' => <<<'PY'
from kawa import Context, Webhook, actor


@actor(receivs=Webhook.by("orders.created"))
def HandleWebhook(ctx: Context, event: Webhook) -> None:
    print(event.payload)
PY,
        ]);

        FlowHistory::query()->create([
            'flow_id' => $flow->id,
            'code' => 'print("legacy")',
            'diff' => null,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $oldRun = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'production',
            'status' => 'completed',
            'active' => false,
            'code_snapshot' => null,
            'graph_snapshot' => null,
            'events' => [
                [
                    'id' => 'event.old',
                    'source_line' => 7,
                    'source_kind' => 'import',
                    'source_module' => 'events.old',
                ],
                [
                    'id' => 'event.done',
                    'source_line' => 11,
                    'source_kind' => 'main',
                ],
            ],
            'actors' => [[
                'id' => 'actor.old',
                'receives' => ['event.old'],
                'sends' => ['event.done'],
                'source_line' => 16,
                'source_kind' => 'import',
                'source_module' => 'actors.old',
            ]],
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2)->addMinutes(5),
        ]);

        $newRun = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'status' => 'running',
            'active' => true,
            'container_id' => 'dev-container-1',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => [
                'nodes' => [['id' => 'node.snapshot', 'type' => 'event', 'label' => 'node.snapshot']],
                'edges' => [],
            ],
            'started_at' => now()->subMinutes(10),
        ]);

        FlowLog::factory()->forRun($oldRun)->createOne([
            'message' => 'Old deployment log',
        ]);

        FlowLog::factory()->count(55)->forRun($newRun)->create();

        $developmentEndpoint = $this->webhookUrl($flow, 'development', 'orders.created');

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->where('lastDevelopmentDeployment.id', $newRun->id)
            ->where('lastDevelopmentDeployment.code', $flow->code)
            ->where('lastDevelopmentDeployment.graph.nodes.0.id', 'node.snapshot')
            ->where('webhookEndpoints.0.slug', 'orders.created')
            ->where('webhookEndpoints.0.production_url', null)
            ->where('webhookEndpoints.0.development_url', $developmentEndpoint)
            ->has('lastDevelopmentDeployment.logs', 50)
            ->has('deployments', 2)
            ->where('productionLogsCount', 0)
            ->where('deployments.0.id', $newRun->id)
            ->where('deployments.0.code', $flow->code)
            ->where('deployments.0.graph.nodes.0.id', 'node.snapshot')
            ->where('deployments.0.webhooks.0.slug', 'orders.created')
            ->where('deployments.0.webhooks.0.development_url', $developmentEndpoint)
            ->has('deployments.0.logs', 50)
            ->where('deployments.1.id', $oldRun->id)
            ->where('deployments.1.code', 'print("legacy")')
            ->where('deployments.1.graph.nodes.0.id', 'event.old')
            ->where('deployments.1.graph.nodes.0.source_line', 7)
            ->where('deployments.1.graph.nodes.0.source_kind', 'import')
            ->where('deployments.1.graph.nodes.0.source_module', 'events.old')
            ->where('deployments.1.graph.nodes.1.id', 'event.done')
            ->where('deployments.1.graph.nodes.1.source_line', 11)
            ->where('deployments.1.graph.nodes.1.source_kind', 'main')
            ->where('deployments.1.graph.nodes.2.id', 'actor.old')
            ->where('deployments.1.graph.nodes.2.source_line', 16)
            ->where('deployments.1.graph.nodes.2.source_kind', 'import')
            ->where('deployments.1.graph.nodes.2.source_module', 'actors.old')
            ->where('deployments.1.webhooks', [])
            ->where('deployments.1.logs.0.message', 'Old deployment log')
            ->missing('productionLogs')
            ->missing('productionRuns')
            ->missing('developmentRun')
            ->missing('developmentLogs')
            ->missing('developmentRuns')
            ->missing('viewMode')
            ->missing('requiresDeletePassword')
        );
    }

    public function test_editor_payload_keeps_last_development_deployment_separate_from_recent_deployments(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne();

        $developmentRun = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'status' => 'stopped',
            'active' => false,
            'graph_snapshot' => [
                'nodes' => [['id' => 'node.latest-dev', 'type' => 'event', 'label' => 'node.latest-dev']],
                'edges' => [],
            ],
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(8),
        ]);

        $runs = FlowRun::factory()->count(7)->forFlow($flow)->sequence(
            fn ($sequence) => [
                'type' => 'production',
                'created_at' => now()->subMinutes(7 - $sequence->index),
                'updated_at' => now()->subMinutes(7 - $sequence->index),
            ],
        )->create();

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->where('lastDevelopmentDeployment.id', $developmentRun->id)
            ->where('lastDevelopmentDeployment.graph.nodes.0.id', 'node.latest-dev')
            ->has('deployments', 5)
            ->where('deployments.0.id', $runs[6]->id)
            ->where('deployments.4.id', $runs[2]->id)
        );
    }

    public function test_editor_payload_does_not_expose_dead_development_webhook_urls(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'code' => <<<'PY'
from kawa import Context, Webhook, actor


@actor(receivs=Webhook.by("orders.created"))
def HandleWebhook(ctx: Context, event: Webhook) -> None:
    print(event.payload)
PY,
        ]);

        $inactiveRun = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'status' => 'stopped',
            'active' => false,
            'code_snapshot' => $flow->code,
            'graph_snapshot' => [
                'nodes' => [['id' => 'Webhook.by("orders.created")', 'type' => 'event', 'label' => 'Webhook.by("orders.created")']],
                'edges' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->where('lastDevelopmentDeployment.id', $inactiveRun->id)
            ->where('webhookEndpoints', [])
            ->where('lastDevelopmentDeployment.webhooks.0.slug', 'orders.created')
            ->where('lastDevelopmentDeployment.webhooks.0.development_url', null)
        );
    }

    public function test_editor_payload_uses_structured_webhook_detection(): void
    {
        $this->travelTo(now()->startOfSecond());

        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'code' => <<<'PY'
from kawa import Context, Webhook as Hook, actor


# Webhook.by("phantom.comment") should never become an endpoint.
@actor(
    receivs=(
        Hook.by("orders.created"),
    )
)
def HandleWebhook(ctx: Context, event) -> None:
    print(event.payload)


DOC = "Webhook.by('phantom.string')"
PY,
        ]);

        $run = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'status' => 'running',
            'active' => true,
            'container_id' => 'dev-container-2',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => null,
        ]);

        $developmentEndpoint = $this->webhookUrl($flow, 'development', 'orders.created');

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->has('webhookEndpoints', 1)
            ->where('webhookEndpoints.0.slug', 'orders.created')
            ->where('webhookEndpoints.0.development_url', $developmentEndpoint)
            ->where('webhookEndpoints.0.source_line', 7)
        );
    }

    public function test_editor_payload_prefers_first_declared_webhook_call_site_over_graph_source_line(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'code' => <<<'PY'
from kawa import Context, Webhook, actor


@actor(receivs=Webhook.by("orders.created"))
def FirstHandler(ctx: Context, event: Webhook) -> None:
    print(event.payload)


@actor(receivs=Webhook.by("orders.created"))
def SecondHandler(ctx: Context, event: Webhook) -> None:
    print(event.payload)
PY,
        ]);

        $run = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'status' => 'running',
            'active' => true,
            'container_id' => 'dev-container-graph-webhook',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => [
                'nodes' => [[
                    'id' => 'Webhook.by("orders.created")',
                    'type' => 'event',
                    'source_line' => 1,
                    'source_kind' => 'import',
                    'source_module' => 'shared.events',
                ]],
                'edges' => [],
                'events' => [[
                    'id' => 'Webhook.by("orders.created")',
                    'source_line' => 1,
                ]],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->where('lastDevelopmentDeployment.id', $run->id)
            ->where('lastDevelopmentDeployment.graph.nodes.0.source_line', 1)
            ->where('lastDevelopmentDeployment.webhooks.0.slug', 'orders.created')
            ->where('lastDevelopmentDeployment.webhooks.0.source_line', 4)
            ->where('webhookEndpoints.0.slug', 'orders.created')
            ->where('webhookEndpoints.0.source_line', 4)
            ->where('deployments.0.webhooks.0.source_line', 4)
        );
    }

    public function test_editor_payload_does_not_append_declared_webhooks_missing_from_graph(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'code' => <<<'PY'
from kawa import Context, Webhook, actor


@actor(receivs=Webhook.by("orders.created"))
def HandleOrders(ctx: Context, event: Webhook) -> None:
    print(event.payload)


@actor(receivs=Webhook.by("users.created"))
def HandleUsers(ctx: Context, event: Webhook) -> None:
    print(event.payload)
PY,
        ]);

        $developmentEndpoint = $this->webhookUrl($flow, 'development', 'orders.created');

        $run = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'status' => 'running',
            'active' => true,
            'container_id' => 'dev-container-graph-only',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => [
                'nodes' => [[
                    'id' => 'Webhook.by("orders.created")',
                    'type' => 'event',
                    'source_line' => 1,
                ]],
                'edges' => [],
                'events' => [[
                    'id' => 'Webhook.by("orders.created")',
                    'source_line' => 1,
                ]],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->where('lastDevelopmentDeployment.id', $run->id)
            ->has('webhookEndpoints', 1)
            ->where('webhookEndpoints.0.slug', 'orders.created')
            ->where('webhookEndpoints.0.development_url', $developmentEndpoint)
            ->has('lastDevelopmentDeployment.webhooks', 1)
            ->where('lastDevelopmentDeployment.webhooks.0.slug', 'orders.created')
            ->has('deployments.0.webhooks', 1)
            ->where('deployments.0.webhooks.0.slug', 'orders.created')
        );
    }

    public function test_editor_payload_prefers_development_source_line_for_shared_webhook_slug(): void
    {
        $this->travelTo(now()->startOfSecond());

        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'code' => <<<'PY'
from kawa import Context, Webhook, actor




@actor(receivs=Webhook.by("orders.created"))
def HandleWebhook(ctx: Context, event: Webhook) -> None:
    print(event.payload)
PY,
            'container_id' => 'prod-container',
        ]);

        FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'production',
            'status' => 'running',
            'active' => true,
            'container_id' => 'prod-container',
            'code_snapshot' => <<<'PY'
from kawa import Context, Webhook, actor


@actor(receivs=Webhook.by("orders.created"))
def HandleWebhook(ctx: Context, event: Webhook) -> None:
    print(event.payload)
PY,
            'graph_snapshot' => null,
        ]);

        $developmentRun = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'status' => 'running',
            'active' => true,
            'container_id' => 'dev-container-3',
            'code_snapshot' => $flow->code,
            'graph_snapshot' => null,
        ]);

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->where('webhookEndpoints.0.slug', 'orders.created')
            ->where('webhookEndpoints.0.source_line', 6)
            ->where('webhookEndpoints.0.production_url', $this->webhookUrl($flow, 'production', 'orders.created'))
            ->where('webhookEndpoints.0.development_url', $this->webhookUrl($flow, 'development', 'orders.created'))
        );
    }

    public function test_editor_payload_hides_active_development_webhook_without_container(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'code' => <<<'PY'
from kawa import Context, Webhook, actor


@actor(receivs=Webhook.by("orders.created"))
def HandleWebhook(ctx: Context, event: Webhook) -> None:
    print(event.payload)
PY,
        ]);

        $run = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'status' => 'running',
            'active' => true,
            'container_id' => null,
            'code_snapshot' => $flow->code,
            'graph_snapshot' => null,
        ]);

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->where('lastDevelopmentDeployment.id', $run->id)
            ->where('webhookEndpoints.0.slug', 'orders.created')
            ->where('webhookEndpoints.0.development_url', null)
            ->where('lastDevelopmentDeployment.webhooks.0.slug', 'orders.created')
            ->where('lastDevelopmentDeployment.webhooks.0.development_url', null)
        );
    }

    public function test_editor_payload_orders_same_second_logs_by_id(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne();
        $run = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'status' => 'running',
            'active' => true,
        ]);
        $timestamp = now()->startOfSecond();

        FlowLog::factory()->forRun($run)->createOne([
            'message' => 'Actor invoked by webhook',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        FlowLog::factory()->forRun($run)->createOne([
            'message' => 'Actor dispatched message',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->where('deployments.0.logs.0.message', 'Actor dispatched message')
            ->where('deployments.0.logs.1.message', 'Actor invoked by webhook')
            ->where(
                'lastDevelopmentDeployment.logs.0.message',
                'Actor dispatched message',
            )
            ->where(
                'lastDevelopmentDeployment.logs.1.message',
                'Actor invoked by webhook',
            )
        );
    }

    public function test_editor_payload_includes_storage_by_environment(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne();

        FlowStorage::factory()->forFlow($flow)->createOne([
            'environment' => 'development',
            'content' => [
                'users' => [
                    ['name' => 'Ada'],
                ],
            ],
        ]);

        FlowStorage::factory()->forFlow($flow)->createOne([
            'environment' => 'production',
            'content' => [
                'settings' => [
                    'profile' => [
                        'language' => 'ru',
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->where('storage.development.users.0.name', 'Ada')
            ->where('storage.production.settings.profile.language', 'ru')
        );
    }

    private function webhookUrl(
        Flow $flow,
        string $environment,
        string $slug,
    ): string {
        return app(FlowWebhookService::class)->webhookUrl($flow, $environment, $slug);
    }
}
