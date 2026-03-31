<?php

namespace App\Http\Controllers;

use App\Http\Requests\Flows\FlowWebhookRequest;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Services\FlowManagerClient;
use App\Services\FlowWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class FlowWebhookController extends Controller
{
    public function __construct(
        private readonly FlowManagerClient $client,
        private readonly FlowWebhookService $webhooks,
    ) {}

    public function show(string $token): InertiaResponse
    {
        $resolved = $this->resolveToken($token);
        $run = $this->resolveRun(
            $resolved['flow'],
            $resolved['environment'],
            $resolved['slug'],
        );
        abort_if(! $run instanceof FlowRun, 404);

        return Inertia::render('webhooks/Show', $this->viewPayload(
            $resolved['flow'],
            $run,
            $resolved['environment'],
            $resolved['slug'],
            route('webhooks.show', ['token' => $token]),
            $token,
        ));
    }

    public function dispatch(FlowWebhookRequest $request, string $token): JsonResponse
    {
        $resolved = $this->resolveToken($token);
        $run = $this->resolveRun(
            $resolved['flow'],
            $resolved['environment'],
            $resolved['slug'],
        );
        abort_if(! $run instanceof FlowRun, 404);

        return $this->dispatchToRuntime(
            $request,
            $resolved['flow'],
            $run,
            $resolved['slug'],
        );
    }

    /**
     * @return array{flow: Flow, environment: 'production'|'development', slug: string}
     */
    private function resolveToken(string $token): array
    {
        $resolved = $this->webhooks->resolveWebhookToken($token);
        abort_if(! is_array($resolved), 404);

        return $resolved;
    }

    private function resolveRun(
        Flow $flow,
        string $environment,
        string $slug,
    ): ?FlowRun {
        if ($environment === 'production') {
            return $this->webhooks->resolveProductionRun($flow, $slug);
        }

        return $this->webhooks->resolveDevelopmentRun($flow, $slug);
    }

    private function dispatchToRuntime(
        FlowWebhookRequest $request,
        Flow $flow,
        FlowRun $run,
        string $slug,
    ): JsonResponse {
        $containerId = $this->webhooks->resolveContainerId($flow, $run);
        abort_if($containerId === null, 404);

        $response = $this->client->sendMessage($containerId, [
            'command' => 'webhook',
            'data' => [
                'slug' => $slug,
                'payload' => $request->payload(),
            ],
        ]);

        if (! ($response['ok'] ?? false)) {
            Log::warning('Webhook dispatch failed.', [
                'flow_id' => $flow->id,
                'run_id' => $run->id,
                'slug' => $slug,
            ]);

            return response()->json([
                'message' => 'Failed to dispatch webhook payload.',
            ], 503);
        }

        return response()->json([
            'ok' => true,
            'status' => 'accepted',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewPayload(
        Flow $flow,
        FlowRun $run,
        string $environment,
        string $slug,
        string $endpoint,
        string $token,
    ): array {
        return [
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
            ],
            'environment' => $environment,
            'run' => [
                'id' => $run->id,
                'type' => $run->type,
            ],
            'slug' => $slug,
            'token' => $token,
            'endpoint' => $endpoint,
            'samplePayload' => json_encode(['message' => 'hello'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }
}
