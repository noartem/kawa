<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowRun;
use App\Services\FlowManagerClient;
use App\Services\FlowWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FlowWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_webhook_page_renders_for_active_production_run(): void
    {
        [$flow] = $this->webhookTarget('production');
        $endpoint = $this->webhookUrl($flow, 'production');

        $response = $this->get($endpoint);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('webhooks/Show')
            ->where('slug', 'orders.created')
            ->where('token', $this->webhookToken($flow, 'production'))
            ->where('endpoint', $endpoint)
            ->where('environment', 'production')
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
        $endpoint = $this->webhookUrl($flow, 'development');

        $response = $this->get($endpoint);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('webhooks/Show')
            ->where('slug', 'orders.created')
            ->where('token', $this->webhookToken($flow, 'development'))
            ->where('endpoint', $endpoint)
            ->where('environment', 'development')
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

        $response = $this->postJson(
            route('webhooks.dispatch', ['token' => $this->webhookToken($flow, 'production')]),
            ['order_id' => 42],
        );

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

        $response = $this->postJson(
            route('webhooks.dispatch', ['token' => $this->webhookToken($flow, 'production', 'users.created')]),
            ['user_id' => 7],
        );

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

        $response = $this->postJson(
            route('webhooks.dispatch', ['token' => $this->webhookToken($flow, 'production')]),
            ['order_id' => 42],
        );

        $response
            ->assertStatus(503)
            ->assertJson([
                'message' => 'Failed to dispatch webhook payload.',
            ]);
    }

    public function test_post_webhook_requires_json_content_type(): void
    {
        [$flow] = $this->webhookTarget('production');

        $response = $this->post(
            route('webhooks.dispatch', ['token' => $this->webhookToken($flow, 'production')]),
            ['order_id' => 42],
        );

        $response->assertSessionHasErrors('payload');
    }

    public function test_post_webhook_returns_404_for_inactive_development_run(): void
    {
        [$flow] = $this->webhookTarget('development', active: false);

        $response = $this->postJson(
            route('webhooks.dispatch', ['token' => $this->webhookToken($flow, 'development')]),
            ['order_id' => 42],
        );

        $response->assertNotFound();
    }

    public function test_development_webhook_token_uses_the_latest_active_run(): void
    {
        [$flow, $stoppedRun] = $this->webhookTarget('development');
        $endpoint = $this->webhookUrl($flow, 'development');

        $stoppedRun->update([
            'active' => false,
            'status' => 'stopped',
        ]);

        $newRun = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'active' => true,
            'status' => 'running',
            'container_id' => 'container-2',
            'code_snapshot' => $flow->code,
        ]);

        $response = $this->get($endpoint);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('webhooks/Show')
            ->where('run.id', $newRun->id)
            ->where('environment', 'development')
        );
    }

    public function test_webhook_endpoint_returns_404_for_invalid_token(): void
    {
        $response = $this->get(route('webhooks.show', ['token' => 'invalid-token']));

        $response->assertNotFound();

        $postResponse = $this->postJson(
            route('webhooks.dispatch', ['token' => 'invalid-token']),
            ['order_id' => 42],
        );

        $postResponse->assertNotFound();
    }

    public function test_webhook_endpoint_returns_404_for_tampered_token(): void
    {
        [$flow] = $this->webhookTarget('production');
        $token = $this->webhookToken($flow, 'production');
        $tamperedToken = substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a');

        $response = $this->get(route('webhooks.show', ['token' => $tamperedToken]));

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

    private function webhookUrl(
        Flow $flow,
        string $environment,
        string $slug = 'orders.created',
    ): string {
        return app(FlowWebhookService::class)->webhookUrl($flow, $environment, $slug);
    }

    private function webhookToken(
        Flow $flow,
        string $environment,
        string $slug = 'orders.created',
    ): string {
        return app(FlowWebhookService::class)->webhookToken($flow, $environment, $slug);
    }
}
