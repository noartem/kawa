<?php

namespace App\Http\Controllers;

use App\Http\Requests\Flows\FlowWebhookRequest;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Services\FlowManagerClient;
use App\Services\FlowWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class FlowWebhookController extends Controller
{
    public function __construct(
        private readonly FlowManagerClient $client,
        private readonly FlowWebhookService $webhooks,
    ) {}

    public function showProduction(Request $request, Flow $flow, string $slug): InertiaResponse
    {
        $this->ensureValidSignature($request);

        $run = $this->webhooks->resolveProductionRun($flow, $slug);
        abort_if(! $run instanceof FlowRun, 404);

        return Inertia::render('webhooks/Show', $this->viewPayload($flow, $run, $slug, $request->fullUrl()));
    }

    public function dispatchProduction(
        FlowWebhookRequest $request,
        Flow $flow,
        string $slug,
    ): JsonResponse {
        $this->ensureValidSignature($request);

        $run = $this->webhooks->resolveProductionRun($flow, $slug);
        abort_if(! $run instanceof FlowRun, 404);

        return $this->dispatch($request, $flow, $run, $slug);
    }

    public function showDevelopment(Request $request, Flow $flow, string $slug): InertiaResponse
    {
        $this->ensureValidSignature($request, requiresExpiration: true);

        $resolvedRun = $this->webhooks->resolveDevelopmentRun(
            $flow,
            $slug,
            $request->integer('run') ?: null,
        );
        abort_if(! $resolvedRun instanceof FlowRun, 404);

        return Inertia::render('webhooks/Show', $this->viewPayload($flow, $resolvedRun, $slug, $request->fullUrl()));
    }

    public function dispatchDevelopment(
        FlowWebhookRequest $request,
        Flow $flow,
        string $slug,
    ): JsonResponse {
        $this->ensureValidSignature($request, requiresExpiration: true);

        $resolvedRun = $this->webhooks->resolveDevelopmentRun(
            $flow,
            $slug,
            $request->integer('run') ?: null,
        );
        abort_if(! $resolvedRun instanceof FlowRun, 404);

        return $this->dispatch($request, $flow, $resolvedRun, $slug);
    }

    private function dispatch(
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
    private function viewPayload(Flow $flow, FlowRun $run, string $slug, string $endpoint): array
    {
        return [
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
            ],
            'run' => [
                'id' => $run->id,
                'type' => $run->type,
            ],
            'slug' => $slug,
            'endpoint' => $endpoint,
            'samplePayload' => json_encode(['message' => 'hello'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function ensureValidSignature(
        Request $request,
        bool $requiresExpiration = false,
    ): void {
        if ($requiresExpiration && (! $request->has('expires') || ! $request->filled('run'))) {
            abort(404);
        }

        abort_unless($request->hasValidSignature(), 404);
    }
}
