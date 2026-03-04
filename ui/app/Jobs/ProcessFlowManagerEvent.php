<?php

namespace App\Jobs;

use App\Events\FlowEventBroadcast;
use App\Models\Flow;
use App\Models\FlowLog;
use App\Models\FlowRun;
use App\Services\FlowManagerClient;
use App\Services\FlowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessFlowManagerEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $event,
        private readonly array $payload = [],
    ) {}

    public function handle(): void
    {
        $flowRun = $this->resolveFlowRun();
        $flow = $flowRun?->flow ?? $this->resolveFlow();

        if (! $flow) {
            Log::info('flow-manager event without flow match', [
                'event' => $this->event,
                'payload' => $this->payload,
            ]);

            return;
        }

        $this->updateFlowStatus($flow, $flowRun);
        $this->syncFlowGraphFromPayload($flow);
        $this->recordLog($flow, $flowRun);
        broadcast(new FlowEventBroadcast($flow->id, $this->event, $this->payload));
    }

    private function resolveFlowRun(): ?FlowRun
    {
        $runId = $this->payload['flow_run_id'] ?? $this->payload['run_id'] ?? null;
        if ($runId) {
            return FlowRun::find($runId);
        }

        $containerId = $this->payload['container_id'] ?? null;
        if ($containerId) {
            return FlowRun::where('container_id', $containerId)->latest()->first();
        }

        return null;
    }

    private function resolveFlow(): ?Flow
    {
        $flowId = $this->payload['flow_id'] ?? null;
        if ($flowId) {
            return Flow::find($flowId);
        }

        $containerId = $this->payload['container_id'] ?? null;
        if ($containerId) {
            return Flow::where('container_id', $containerId)->first();
        }

        return null;
    }

    private function updateFlowStatus(Flow $flow, ?FlowRun $flowRun): void
    {
        $status = $this->payload['new_state'] ?? $this->payload['status'] ?? null;

        if ($this->event === 'container_created') {
            $containerId = $this->payload['container_id'] ?? null;
            if ($containerId) {
                if ($flowRun) {
                    $flowRun->update([
                        'container_id' => $containerId,
                        'meta' => $this->payload,
                    ]);
                }

                $flow->update([
                    'container_id' => $containerId,
                ]);
            }

            return;
        }

        if ($this->event === 'lock_generated' && $flowRun) {
            $flowRun->update([
                'lock' => $this->payload['lock'] ?? null,
                'status' => 'locked',
                'meta' => $this->payload,
            ]);

            app(FlowService::class)->markLockReady($flow, $flowRun);

            return;
        }

        if ($this->event === 'lock_failed' && $flowRun) {
            $flowRun->update([
                'status' => 'lock_failed',
                'meta' => $this->payload,
            ]);

            return;
        }

        if ($this->event === 'container_crashed') {
            if ($flowRun) {
                $flowRun->update([
                    'status' => 'error',
                    'finished_at' => now(),
                    'meta' => $this->payload,
                ]);
            }

            $this->syncFlowStatusFromRun($flow, $flowRun, 'error');

            return;
        }

        if (! $status) {
            return;
        }

        if ($status === 'running') {
            if ($flowRun) {
                $flowRun->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'meta' => $this->payload,
                ]);
            }

            $this->syncFlowStatusFromRun($flow, $flowRun, 'running');

            return;
        }

        if (in_array($status, ['stopped', 'exited', 'finished', 'dead'], true)) {
            if ($flowRun) {
                $flowRun->update([
                    'status' => 'stopped',
                    'finished_at' => now(),
                    'meta' => $this->payload,
                ]);
            }

            $this->syncFlowStatusFromRun($flow, $flowRun, 'stopped');
        }
    }

    private function syncFlowStatusFromRun(Flow $flow, ?FlowRun $run, string $status): void
    {
        if (! $run || $run->type !== 'production' || ! $run->active) {
            return;
        }

        $payload = $status === 'running'
            ? ['status' => $status, 'last_started_at' => now()]
            : ['status' => $status, 'last_finished_at' => now()];

        $flow->update($payload);
    }

    private function recordLog(Flow $flow, ?FlowRun $flowRun): void
    {
        $level = match ($this->event) {
            'container_health_warning', 'resource_alert' => 'warning',
            'container_crashed' => 'error',
            default => 'info',
        };

        FlowLog::create([
            'flow_id' => $flow->id,
            'flow_run_id' => $flowRun?->id,
            'level' => $level,
            'message' => sprintf('Event: %s', $this->event),
            'context' => $this->payload,
        ]);

        if ($flowRun && (isset($this->payload['actors']) || isset($this->payload['events']))) {
            $flowRun->update([
                'actors' => $this->payload['actors'] ?? $flowRun->actors,
                'events' => $this->payload['events'] ?? $flowRun->events,
            ]);
        }
    }

    private function syncFlowGraphFromPayload(Flow $flow): void
    {
        $events = $this->payload['events'] ?? null;
        $actors = $this->payload['actors'] ?? null;

        if (! is_array($events) && ! is_array($actors)) {
            if ($this->event === 'container_created') {
                $this->syncFlowGraphFromRuntime($flow);
            }

            return;
        }

        $existingGraph = is_array($flow->graph) ? $flow->graph : [];
        $graphEvents = is_array($events) ? $events : ($existingGraph['events'] ?? []);
        $graphActors = is_array($actors) ? $actors : ($existingGraph['actors'] ?? []);

        $this->updateFlowGraph($flow, $graphEvents, $graphActors);
    }

    private function syncFlowGraphFromRuntime(Flow $flow): void
    {
        $containerId = $this->payload['container_id'] ?? $flow->container_id;

        if (! is_string($containerId) || $containerId === '') {
            return;
        }

        $graph = app(FlowManagerClient::class)->containerGraph($containerId);

        if (! is_array($graph)) {
            return;
        }

        $graphEvents = is_array($graph['events'] ?? null) ? $graph['events'] : [];
        $graphActors = is_array($graph['actors'] ?? null) ? $graph['actors'] : [];

        $this->updateFlowGraph($flow, $graphEvents, $graphActors);
    }

    /**
     * @param  list<mixed>  $graphEvents
     * @param  list<mixed>  $graphActors
     */
    private function updateFlowGraph(Flow $flow, array $graphEvents, array $graphActors): void
    {

        $nodesById = [];
        $edgesById = [];

        foreach ($graphEvents as $event) {
            $eventId = $this->resolveEntityId($event);

            if ($eventId === null) {
                continue;
            }

            $nodesById[$eventId] = [
                'id' => $eventId,
                'type' => 'event',
                'label' => $eventId,
            ];
        }

        foreach ($graphActors as $actor) {
            if (! is_array($actor)) {
                continue;
            }

            $actorId = $this->resolveEntityId($actor);

            if ($actorId === null) {
                continue;
            }

            $nodesById[$actorId] = [
                'id' => $actorId,
                'type' => 'actor',
                'label' => $actorId,
            ];

            foreach ($this->normalizeEventNames($actor['receives'] ?? []) as $eventId) {
                $nodesById[$eventId] = [
                    'id' => $eventId,
                    'type' => 'event',
                    'label' => $eventId,
                ];
                $edgeKey = $eventId.'->'.$actorId;
                $edgesById[$edgeKey] = [
                    'from' => $eventId,
                    'to' => $actorId,
                ];
            }

            foreach ($this->normalizeEventNames($actor['sends'] ?? []) as $eventId) {
                $nodesById[$eventId] = [
                    'id' => $eventId,
                    'type' => 'event',
                    'label' => $eventId,
                ];
                $edgeKey = $actorId.'->'.$eventId;
                $edgesById[$edgeKey] = [
                    'from' => $actorId,
                    'to' => $eventId,
                ];
            }
        }

        if ($nodesById === [] && $edgesById === []) {
            return;
        }

        $flow->update([
            'graph' => [
                'events' => $graphEvents,
                'actors' => $graphActors,
                'nodes' => array_values($nodesById),
                'edges' => array_values($edgesById),
            ],
            'graph_generated_at' => now(),
        ]);
    }

    private function resolveEntityId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $id = $value['id'] ?? $value['name'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    private function normalizeEventNames(mixed $values): array
    {
        if (is_string($values) && $values !== '') {
            return [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        $result = [];

        foreach ($values as $value) {
            $eventId = $this->resolveEntityId($value);

            if ($eventId === null) {
                continue;
            }

            $result[] = $eventId;
        }

        return array_values(array_unique($result));
    }
}
