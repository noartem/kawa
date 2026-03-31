<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowRun;
use App\Services\FlowManagerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FlowWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_webhook_page_renders_for_active_production_run(): void
    {
        [$flow] = $this->webhookTarget('production');

        $endpoint = URL::signedRoute('webhooks.production.show', [
            'flow' => $flow,
            'slug' => 'orders.created',
        ]);

        $response = $this->get($endpoint);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('webhooks/Show')
            ->where('slug', 'orders.created')
            ->where('endpoint', $endpoint)
            ->where('flow.id', $flow->id)
            ->where('flow.name', $flow->name)
            ->missing('flow.code')
            ->where('run.type', 'production')
            ->missing('run.container_id')
            ->where('samplePayload', "{\n    \"message\": \"hello\"\n}")
        );
    }

    public function test_get_webhook_page_renders_for_active_development_run(): void
    {
        [$flow, $run] = $this->webhookTarget('development');

        $endpoint = $this->temporaryDevelopmentRoute('webhooks.development.show', $flow, $run);

        $response = $this->get($endpoint);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('webhooks/Show')
            ->where('slug', 'orders.created')
            ->where('endpoint', $endpoint)
            ->where('flow.id', $flow->id)
            ->where('run.id', $run->id)
            ->where('run.type', 'development')
            ->missing('run.code_snapshot')
        );
    }

    public function test_post_webhook_dispatches_payload_to_runtime(): void
    {
        [$flow, $run] = $this->webhookTarget('production');

        $this->mock(FlowManagerClient::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->with(
                'container-1',
                [
                    'command' => 'webhook',
                    'data' => [
                        'slug' => 'orders.created',
                        'payload' => ['order_id' => 42],
                    ],
                ],
            )
            ->andReturn(['ok' => true]);

        $response = $this->postJson(URL::signedRoute('webhooks.production.dispatch', [
            'flow' => $flow,
            'slug' => 'orders.created',
        ]), ['order_id' => 42]);

        $response
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'status' => 'accepted',
            ]);

        $run->refresh();
        $this->assertSame('container-1', $run->container_id);
    }

    public function test_post_webhook_returns_404_for_unknown_slug(): void
    {
        [$flow] = $this->webhookTarget('production');

        $response = $this->postJson(URL::signedRoute('webhooks.production.dispatch', [
            'flow' => $flow,
            'slug' => 'users.created',
        ]), ['user_id' => 7]);

        $response->assertNotFound();
    }

    public function test_post_webhook_hides_internal_dispatch_errors(): void
    {
        [$flow] = $this->webhookTarget('production');

        $this->mock(FlowManagerClient::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->andReturn([
                'ok' => false,
                'message' => 'RabbitMQ timeout while contacting runtime.',
            ]);

        $response = $this->postJson(URL::signedRoute('webhooks.production.dispatch', [
            'flow' => $flow,
            'slug' => 'orders.created',
        ]), ['order_id' => 42]);

        $response
            ->assertStatus(503)
            ->assertJson([
                'message' => 'Failed to dispatch webhook payload.',
            ]);
    }

    public function test_post_webhook_requires_json_content_type(): void
    {
        [$flow] = $this->webhookTarget('production');

        $response = $this->post(URL::signedRoute('webhooks.production.dispatch', [
            'flow' => $flow,
            'slug' => 'orders.created',
        ]), ['order_id' => 42]);

        $response->assertSessionHasErrors('payload');
    }

    public function test_post_webhook_returns_404_for_inactive_development_run(): void
    {
        [$flow, $run] = $this->webhookTarget('development', active: false);

        $response = $this->postJson(
            $this->temporaryDevelopmentRoute('webhooks.development.dispatch', $flow, $run),
            ['order_id' => 42],
        );

        $response->assertNotFound();
    }

    public function test_development_webhook_endpoints_require_an_expiring_signature(): void
    {
        [$flow, $run] = $this->webhookTarget('development');

        $response = $this->get(URL::signedRoute('webhooks.development.show', [
            'flow' => $flow,
            'run' => $run->id,
            'slug' => 'orders.created',
        ]));

        $response->assertNotFound();
    }

    public function test_webhook_endpoints_require_a_valid_signature(): void
    {
        [$flow] = $this->webhookTarget('production');

        $response = $this->postJson(route('webhooks.production.dispatch', [
            'flow' => $flow,
            'slug' => 'orders.created',
        ]), ['order_id' => 42]);

        $response->assertNotFound();
    }

    /**
     * @return array{0: Flow, 1: FlowRun}
     */
    private function webhookTarget(string $type, bool $active = true): array
    {
        $flow = Flow::factory()->createOne([
            'code' => <<<'PY'
from kawa import Context, Webhook, actor


@actor(receivs=Webhook.by("orders.created"))
def HandleWebhook(ctx: Context, event: Webhook) -> None:
    print(event.payload)
PY,
            'container_id' => $type === 'production' ? 'container-1' : null,
        ]);

        $run = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => $type,
            'active' => $active,
            'status' => $active ? 'running' : 'stopped',
            'container_id' => 'container-1',
            'code_snapshot' => $flow->code,
        ]);

        return [$flow, $run];
    }

    private function temporaryDevelopmentRoute(
        string $routeName,
        Flow $flow,
        FlowRun $run,
    ): string {
        return URL::temporarySignedRoute($routeName, now()->addMinutes(1440), [
            'flow' => $flow,
            'run' => $run->id,
            'slug' => 'orders.created',
        ]);
    }
}
