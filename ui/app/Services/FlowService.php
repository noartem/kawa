<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\FlowRun;
use Illuminate\Support\Facades\File;

final readonly class FlowService
{
    public function __construct(
        private FlowManagerClient $client,
    ) {}

    public function getStatus(Flow $flow): string
    {
        $productionRun = $flow->activeRun('production');
        if ($productionRun) {
            return $productionRun->status ?? 'unknown';
        }

        return $flow->status ?? 'draft';
    }

    public function start(Flow $flow): array
    {
        return $this->deployDevelopment($flow);
    }

    public function stop(Flow $flow): array
    {
        return $this->stopDeployment($flow, 'development');
    }

    public function delete(Flow $flow): array
    {
        $activeRuns = $flow->runs()->where('active', true)->get();
        foreach ($activeRuns as $run) {
            $this->stopDeployment($flow, $run->type);
        }

        $containerIds = $flow->runs()
            ->whereNotNull('container_id')
            ->pluck('container_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($containerIds as $containerId) {
            $this->client->deleteContainer((string) $containerId);
        }

        return ['ok' => true];
    }

    public function deployProduction(Flow $flow): array
    {
        $run = $this->createDeployment($flow, 'production', 'locking');

        $response = $this->client->generateLock([
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'image' => $flow->image,
            'code' => $this->runtimeEntrypointScript((string) ($run->code_snapshot ?? $flow->code ?? '')),
        ]);

        if (! ($response['ok'] ?? false)) {
            $errorMessage = $this->resolveFlowManagerError($response, __('flows.deploy.error'));

            $run->update([
                'active' => false,
                'status' => 'lock_failed',
                'finished_at' => now(),
                'meta' => [
                    'error' => $response['message'] ?? null,
                    'error_type' => $response['error_type'] ?? null,
                    'details' => $response['details'] ?? [],
                    'correlation_id' => $response['correlation_id'] ?? null,
                ],
            ]);

            $run->logs()->create([
                'flow_id' => $flow->id,
                'flow_run_id' => $run->id,
                'level' => 'error',
                'message' => $errorMessage,
                'context' => $response,
            ]);

            $flow->update([
                'status' => 'error',
                'last_finished_at' => now(),
            ]);

            return ['ok' => false, 'run_id' => $run->id, 'message' => $errorMessage];
        }

        $responseData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $lock = is_string($responseData['lock'] ?? null) ? $responseData['lock'] : null;

        if ($lock !== null && $lock !== '') {
            $run->update([
                'lock' => $lock,
                'status' => 'locked',
                'meta' => $responseData,
            ]);

            $deployment = $this->markLockReady($flow, $run);

            if (! ($deployment['ok'] ?? false)) {
                return $deployment;
            }
        }

        $run->logs()->create([
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'level' => 'info',
            'message' => 'Production deployment requested.',
            'context' => $responseData,
        ]);

        return ['ok' => true, 'run_id' => $run->id];
    }

    public function undeployProduction(Flow $flow): array
    {
        return $this->stopDeployment($flow, 'production');
    }

    /**
     * @return array{ok: bool, run_id?: int, message?: string}
     */
    public function markLockReady(Flow $flow, FlowRun $run): array
    {
        if ($run->type !== 'production') {
            return ['ok' => true, 'run_id' => $run->id];
        }

        $this->writeDeploymentFiles($flow, $run);
        $run->update([
            'status' => 'ready',
        ]);

        return $this->requestRuntimeDeployment(
            $flow,
            $run,
            __('flows.deploy.error'),
            'Production deployment requested.',
        );
    }

    private function deployDevelopment(Flow $flow): array
    {
        $run = $this->createDeployment($flow, 'development', 'creating');

        return $this->requestRuntimeDeployment(
            $flow,
            $run,
            __('flows.run.error'),
            'Development deployment requested.',
        );
    }

    private function stopDeployment(Flow $flow, string $type): array
    {
        $run = $flow->activeRun($type);
        if (! $run) {
            return ['ok' => true];
        }

        if ($run->finished_at !== null) {
            $this->finalizeStoppedRun($flow, $run);

            $run->logs()->create([
                'flow_id' => $flow->id,
                'flow_run_id' => $run->id,
                'level' => 'info',
                'message' => 'Deployment stop requested.',
                'context' => [
                    'deployment_type' => $type,
                    'container_id' => $run->container_id,
                    'normalized_terminal_run' => true,
                ],
            ]);

            return ['ok' => true];
        }

        $run->update([
            'status' => 'stopping',
        ]);

        if ($type === 'production' && in_array($flow->status, ['running', 'stopping'], true)) {
            $flow->update([
                'status' => 'stopping',
            ]);
        }

        $containerId = $run->container_id ?? $flow->container_id;
        if (! $containerId) {
            $containerId = $this->resolveRuntimeContainerId($flow, $run);
        }

        if (! $containerId) {
            $deadline = now()->addSeconds(5);
            while (! $containerId && now()->lt($deadline)) {
                usleep(200000);
                $run->refresh();
                $flow->refresh();
                $containerId = $run->container_id ?? $flow->container_id;
            }
        }

        if (! $containerId) {
            $containerId = $this->resolveRuntimeContainerId($flow, $run);
        }

        if ($containerId) {
            $this->client->stopContainer($containerId);
        } else {
            $this->finalizeStoppedRun($flow, $run);
        }

        $run->logs()->create([
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'level' => 'info',
            'message' => 'Deployment stop requested.',
            'context' => [
                'deployment_type' => $type,
                'container_id' => $containerId,
            ],
        ]);

        return ['ok' => true];
    }

    private function finalizeStoppedRun(Flow $flow, FlowRun $run): void
    {
        $finishedAt = $run->finished_at ?? now();

        $run->update([
            'active' => false,
            'status' => 'stopped',
            'finished_at' => $finishedAt,
        ]);

        if ($run->container_id !== null && $flow->container_id === $run->container_id) {
            $flow->update([
                'container_id' => null,
            ]);
        }

        if ($run->type === 'production') {
            $flow->update([
                'status' => 'stopped',
                'last_finished_at' => $finishedAt,
            ]);
        }
    }

    private function createDeployment(Flow $flow, string $type, string $status): FlowRun
    {
        $graphSnapshot = $this->latestGraphSnapshot($flow);

        $flow->runs()
            ->where('type', $type)
            ->where('active', true)
            ->update([
                'active' => false,
                'finished_at' => now(),
            ]);

        $run = $flow->runs()->create([
            'type' => $type,
            'active' => true,
            'status' => $status,
            'code_snapshot' => $flow->code ?? '',
            'graph_snapshot' => $graphSnapshot,
            'started_at' => now(),
        ]);

        if ($type === 'development') {
            $flow->update([
                'container_id' => null,
            ]);

            $this->writeDeploymentFiles($flow, $run);
        }

        if ($type === 'production') {
            $flow->update([
                'container_id' => null,
                'status' => 'deploying',
                'last_started_at' => now(),
            ]);
        }

        return $run;
    }

    private function requestRuntimeDeployment(
        Flow $flow,
        FlowRun $run,
        string $fallbackErrorMessage,
        string $requestedLogMessage,
    ): array {
        $response = $this->client->createContainer($this->runtimeContainerPayload($flow, $run));

        if (! ($response['ok'] ?? false)) {
            $errorMessage = $this->resolveFlowManagerError($response, $fallbackErrorMessage);

            $run->update([
                'active' => false,
                'status' => 'error',
                'finished_at' => now(),
                'meta' => [
                    'error' => $response['message'] ?? null,
                    'error_type' => $response['error_type'] ?? null,
                    'details' => $response['details'] ?? [],
                    'correlation_id' => $response['correlation_id'] ?? null,
                ],
            ]);

            $run->logs()->create([
                'flow_id' => $flow->id,
                'flow_run_id' => $run->id,
                'level' => 'error',
                'message' => $errorMessage,
                'context' => $response,
            ]);

            if ($run->type === 'production') {
                $flow->update([
                    'status' => 'error',
                    'last_finished_at' => now(),
                ]);
            }

            return [
                'ok' => false,
                'run_id' => $run->id,
                'message' => $errorMessage,
            ];
        }

        $responseData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $containerId = is_string($responseData['container_id'] ?? null)
            ? $responseData['container_id']
            : null;

        $runUpdates = [
            'meta' => $responseData,
        ];

        if ($containerId !== null && $containerId !== '') {
            $runUpdates['container_id'] = $containerId;
            $runUpdates['status'] = 'created';

            $flow->update([
                'container_id' => $containerId,
            ]);
        }

        $run->update($runUpdates);

        $run->logs()->create([
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'level' => 'info',
            'message' => $requestedLogMessage,
            'context' => $responseData,
        ]);

        return [
            'ok' => true,
            'run_id' => $run->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeContainerPayload(Flow $flow, FlowRun $run): array
    {
        $deploymentRoot = $this->deploymentRoot($flow, $run);
        $timezone = $flow->timezone ?? config('app.timezone', 'UTC');
        $graphSnapshot = is_array($run->graph_snapshot) ? $run->graph_snapshot : [];

        $payload = [
            'image' => $flow->image ?? 'flow:dev',
            'name' => sprintf('flow-%d-run-%d', $flow->id, $run->id),
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'flow_name' => $flow->name,
            'storage' => $this->storagePayload($flow, $run->type),
            'graph_hash' => $this->graphHash($flow),
            'events' => is_array($graphSnapshot['events'] ?? null)
                ? $graphSnapshot['events']
                : [],
            'actors' => is_array($graphSnapshot['actors'] ?? null)
                ? $graphSnapshot['actors']
                : [],
            'test_run_id' => (string) config('services.flow_manager.test_run_id'),
            'labels' => [
                'kawaflow.flow_id' => (string) $flow->id,
                'kawaflow.flow_run_id' => (string) $run->id,
                'kawaflow.flow_name' => $flow->name,
                'kawaflow.graph_hash' => $this->graphHash($flow),
                'kawaflow.timezone' => $timezone,
                'kawaflow.deployment_type' => $run->type,
            ],
            'environment' => [
                'FLOW_ID' => (string) $flow->id,
                'FLOW_RUN_ID' => (string) $run->id,
                'FLOW_ENVIRONMENT' => $run->type,
                'FLOW_TIMEZONE' => $timezone,
                'FLOW_PATH' => '/flow/flow.py',
            ],
            'volumes' => [
                $deploymentRoot => '/flow',
            ],
            'command' => [
                'uv',
                'run',
                '/flow/main.py',
            ],
            'working_dir' => '/flow',
        ];

        if (! $payload['test_run_id']) {
            unset($payload['test_run_id']);
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private function storagePayload(Flow $flow, string $environment): array
    {
        $storage = $flow->storageForEnvironment($environment);

        return is_array($storage?->content) ? $storage->content : [];
    }

    private function resolveRuntimeContainerId(Flow $flow, FlowRun $run): ?string
    {
        $response = $this->client->listContainers();
        if (! ($response['ok'] ?? false)) {
            return null;
        }

        $containers = $response['data']['containers'] ?? null;
        if (! is_array($containers)) {
            return null;
        }

        $expectedName = sprintf('flow-%d-run-%d', $flow->id, $run->id);

        foreach ($containers as $container) {
            if (! is_array($container)) {
                continue;
            }

            if (($container['name'] ?? null) !== $expectedName) {
                continue;
            }

            $containerId = $container['id'] ?? null;
            if (! is_string($containerId) || $containerId === '') {
                return null;
            }

            $run->update([
                'container_id' => $containerId,
            ]);

            $flow->update([
                'container_id' => $containerId,
            ]);

            return $containerId;
        }

        return null;
    }

    private function writeDeploymentFiles(Flow $flow, FlowRun $run): void
    {
        $root = $this->deploymentRoot($flow, $run);
        File::ensureDirectoryExists($root);

        $code = is_string($run->code_snapshot) ? $run->code_snapshot : ($flow->code ?? '');

        File::put($root.'/flow.py', $code);
        File::put($root.'/main.py', $this->runtimeEntrypointScript($code));

        if ($run->type === 'production' && $run->lock) {
            File::put($root.'/uv.lock', $run->lock);
        }
    }

    private function deploymentRoot(Flow $flow, FlowRun $run): string
    {
        return storage_path(sprintf('app/flows/%d/%s/%d', $flow->id, $run->type, $run->id));
    }

    private function runtimeEntrypointScript(string $flowCode): string
    {
        $header = $this->extractPep723ScriptHeader($flowCode);

        $bootstrap = <<<'PY'
from kawa.runtime.app import main


if __name__ == '__main__':
    main()
PY;

        return ($header !== '' ? $header."\n\n" : '').$bootstrap."\n";
    }

    private function extractPep723ScriptHeader(string $flowCode): string
    {
        $normalizedCode = str_replace(["\r\n", "\r"], "\n", $flowCode);

        if (! preg_match('/\A(?:#![^\n]*\n)?(# \/\/\/ script\n(?:#.*\n)*# \/\/\/(?:\n|\z))/', $normalizedCode, $matches)) {
            return '';
        }

        return rtrim($matches[1]);
    }

    private function graphHash(Flow $flow): string
    {
        return hash('sha256', (string) ($flow->code ?? ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestGraphSnapshot(Flow $flow): ?array
    {
        $run = $flow->runs()
            ->whereNotNull('graph_snapshot')
            ->latest('updated_at')
            ->orderByDesc('id')
            ->first();

        if (! $run instanceof FlowRun) {
            return null;
        }

        $graphSnapshot = $run->graph_snapshot;

        return is_array($graphSnapshot) && $graphSnapshot !== [] ? $graphSnapshot : null;
    }

    private function resolveFlowManagerError(array $response, string $fallback): string
    {
        $details = $response['details'] ?? [];
        $data = is_array($details) ? ($details['data'] ?? []) : [];
        $image = is_array($data) ? ($data['image'] ?? null) : null;
        $errorType = $response['error_type'] ?? null;

        if ($errorType === 'image_not_found' && is_string($image) && $image !== '') {
            return __('flows.run.image_not_found', ['image' => $image]);
        }

        $message = $response['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return $fallback;
    }
}
