<?php

namespace App\Jobs;

use App\Events\FlowEventBroadcast;
use App\Mail\FlowRuntimeEmail;
use App\Models\Flow;
use App\Models\FlowLog;
use App\Models\FlowRun;
use App\Services\FlowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ProcessFlowManager implements ShouldQueue
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
            return;
        }

        $this->updateFlowStatus($flow, $flowRun);
        $this->syncFlowGraphFromPayload($flowRun);
        $this->syncFlowStorageFromPayload($flow);
        $this->recordLog($flow, $flowRun);
        broadcast(new FlowEventBroadcast($flow->id, $this->event, $this->payload));
        $this->handleRuntimeEmailDispatch($flow, $flowRun);
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
            if ($flowRun) {
                $updates = [
                    'status' => 'created',
                    'meta' => $this->payload,
                ];

                if ($containerId) {
                    $updates['container_id'] = $containerId;
                }

                $flowRun->update($updates);
            }

            if ($containerId) {
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
                if ($flowRun->status === 'stopped' && $flowRun->active === false) {
                    return;
                }

                $flowRun->update([
                    'active' => false,
                    'status' => 'error',
                    'finished_at' => now(),
                    'meta' => $this->payload,
                ]);
            }

            $this->clearFlowContainerBinding($flow);

            $this->syncFlowStatusFromRun($flow, $flowRun, 'error');

            return;
        }

        if (! $status) {
            return;
        }

        if ($status === 'running') {
            if ($flowRun) {
                $flowRun->update([
                    'active' => true,
                    'status' => 'running',
                    'started_at' => now(),
                    'meta' => $this->payload,
                ]);
            }

            $this->syncFlowStatusFromRun($flow, $flowRun, 'running');

            return;
        }

        if ($status === 'created') {
            if ($flowRun) {
                $flowRun->update([
                    'active' => true,
                    'status' => 'created',
                    'meta' => $this->payload,
                ]);
            }

            $this->syncFlowStatusFromRun($flow, $flowRun, 'created');

            return;
        }

        if ($status === 'stopping') {
            if ($flowRun) {
                $flowRun->update([
                    'active' => true,
                    'status' => 'stopping',
                    'meta' => $this->payload,
                ]);
            }

            $this->syncFlowStatusFromRun($flow, $flowRun, 'stopping');

            return;
        }

        if (in_array($status, ['stopped', 'exited', 'finished', 'dead'], true)) {
            if ($flowRun) {
                $flowRun->update([
                    'active' => false,
                    'status' => 'stopped',
                    'finished_at' => now(),
                    'meta' => $this->payload,
                ]);
            }

            $this->clearFlowContainerBinding($flow);

            $this->syncFlowStatusFromRun($flow, $flowRun, 'stopped');
        }
    }

    private function clearFlowContainerBinding(Flow $flow): void
    {
        $payloadContainerId = $this->payload['container_id'] ?? null;

        if (! is_string($payloadContainerId) || $payloadContainerId === '') {
            return;
        }

        if ($flow->container_id !== $payloadContainerId) {
            return;
        }

        $flow->update([
            'container_id' => null,
        ]);
    }

    private function syncFlowStatusFromRun(Flow $flow, ?FlowRun $run, string $status): void
    {
        if (! $run || $run->type !== 'production') {
            return;
        }

        if (! in_array($status, ['stopped', 'error'], true) && ! $run->active) {
            return;
        }

        $payload = match ($status) {
            'running' => ['status' => $status, 'last_started_at' => now()],
            'stopped' => ['status' => $status, 'last_finished_at' => now()],
            default => ['status' => $status],
        };

        $flow->update($payload);
    }

    private function recordLog(Flow $flow, ?FlowRun $flowRun): void
    {
        $level = match ($this->event) {
            'container_health_warning', 'resource_alert' => 'warning',
            'container_crashed' => 'error',
            default => 'info',
        };

        $this->createFlowLog(
            $flow,
            $flowRun,
            $level,
            sprintf('Event: %s', $this->event),
            $this->logContext(),
        );

        if ($flowRun && (isset($this->payload['actors']) || isset($this->payload['events']))) {
            $flowRun->update([
                'actors' => $this->payload['actors'] ?? $flowRun->actors,
                'events' => $this->payload['events'] ?? $flowRun->events,
            ]);
        }
    }

    private function handleRuntimeEmailDispatch(Flow $flow, ?FlowRun $flowRun): void
    {
        $mailPayload = $this->resolveRuntimeEmailPayload();

        if ($mailPayload === null) {
            return;
        }

        $recipients = $this->normalizeRecipients($mailPayload['recipient'] ?? null);

        if ($recipients === []) {
            $recipients = $this->normalizeRecipients($flow->user?->email);
        }

        if ($recipients === []) {
            $this->createFlowLog(
                $flow,
                $flowRun,
                'warning',
                'SendEmail skipped: recipient not resolved.',
                ['event' => 'SendEmail'],
            );

            return;
        }

        try {
            Mail::to($recipients)->send(new FlowRuntimeEmail(
                $this->runtimeEmailSubject($flow, $mailPayload['subject'] ?? null),
                $mailPayload['message'],
            ));

            $this->createFlowLog(
                $flow,
                $flowRun,
                'info',
                'Email sent.',
                ['event' => 'SendEmail'],
            );
        } catch (Throwable $exception) {
            report($exception);

            $this->createFlowLog(
                $flow,
                $flowRun,
                'error',
                'SendEmail failed during delivery.',
                [
                    'event' => 'SendEmail',
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(): array
    {
        if (! $this->isRuntimeSendEmailEvent()) {
            return $this->payload;
        }

        $context = $this->payload;
        $mailPayload = is_array($context['payload'] ?? null) ? $context['payload'] : [];
        $context['payload'] = [
            'message_redacted' => true,
            'recipient_count' => count($this->normalizeRecipients($mailPayload['recipient'] ?? null)),
            'subject_present' => $this->normalizeSubject($mailPayload['subject'] ?? null) !== null,
        ];

        return $context;
    }

    private function isRuntimeSendEmailEvent(): bool
    {
        if ($this->event !== 'flow_runtime_event') {
            return false;
        }

        if (($this->payload['kind'] ?? null) !== 'event_dispatched') {
            return false;
        }

        return ($this->payload['event'] ?? null) === 'SendEmail';
    }

    /**
     * @return array{message: string, recipient?: mixed, subject?: mixed}|null
     */
    private function resolveRuntimeEmailPayload(): ?array
    {
        if (! $this->isRuntimeSendEmailEvent()) {
            return null;
        }

        $mailPayload = $this->payload['payload'] ?? null;

        if (! is_array($mailPayload)) {
            return null;
        }

        if (! is_string($mailPayload['message'] ?? null)) {
            return null;
        }

        return $mailPayload;
    }

    /**
     * @return list<string>
     */
    private function normalizeRecipients(mixed $value): array
    {
        if (is_string($value)) {
            $recipient = trim($value);

            return $recipient === '' ? [] : [$recipient];
        }

        if (! is_array($value)) {
            return [];
        }

        $recipients = [];

        foreach ($value as $recipient) {
            if (! is_string($recipient)) {
                continue;
            }

            $normalizedRecipient = trim($recipient);

            if ($normalizedRecipient === '') {
                continue;
            }

            $recipients[] = $normalizedRecipient;
        }

        return array_values(array_unique($recipients));
    }

    private function normalizeSubject(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $subject = trim($value);

        if ($subject === '') {
            return null;
        }

        return $subject;
    }

    private function runtimeEmailSubject(Flow $flow, mixed $value): string
    {
        $subject = $this->normalizeSubject($value);

        if ($subject !== null) {
            return $subject;
        }

        $flowName = trim((string) $flow->name);

        if ($flowName === '') {
            return 'Flow notification';
        }

        return sprintf('Flow "%s" notification', $flowName);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createFlowLog(
        Flow $flow,
        ?FlowRun $flowRun,
        string $level,
        string $message,
        array $context,
    ): void {
        FlowLog::create([
            'flow_id' => $flow->id,
            'flow_run_id' => $flowRun?->id,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
    }

    private function syncFlowGraphFromPayload(?FlowRun $flowRun): void
    {
        $events = $this->payload['events'] ?? null;
        $actors = $this->payload['actors'] ?? null;

        if (! is_array($events) && ! is_array($actors)) {
            return;
        }

        $existingGraph = is_array($flowRun?->graph_snapshot) ? $flowRun->graph_snapshot : [];
        $graphEvents = is_array($events) ? $events : ($existingGraph['events'] ?? []);
        $graphActors = is_array($actors) ? $actors : ($existingGraph['actors'] ?? []);

        $this->updateFlowGraph($flowRun, $graphEvents, $graphActors);
    }

    private function syncFlowStorageFromPayload(Flow $flow): void
    {
        $environment = $this->payload['environment'] ?? null;
        $storage = $this->payload['storage'] ?? null;

        if (! in_array($environment, ['development', 'production'], true) || ! is_array($storage)) {
            return;
        }

        $flow->storages()->updateOrCreate(
            ['environment' => $environment],
            ['content' => $storage],
        );
    }

    /**
     * @param  list<mixed>  $graphEvents
     * @param  list<mixed>  $graphActors
     */
    private function updateFlowGraph(?FlowRun $flowRun, array $graphEvents, array $graphActors): void
    {
        if (! $flowRun) {
            return;
        }

        $nodesById = [];
        $edgesById = [];

        foreach ($graphEvents as $event) {
            $eventId = $this->resolveEntityId($event);

            if ($eventId === null) {
                continue;
            }

            $nodesById[$eventId] = $this->buildEventNode($eventId, $event);
        }

        foreach ($graphActors as $actor) {
            if (! is_array($actor)) {
                continue;
            }

            $actorId = $this->resolveEntityId($actor);

            if ($actorId === null) {
                continue;
            }

            $nodesById[$actorId] = $this->buildActorNode($actorId, $actor);

            foreach ($this->normalizeEventNames($actor['receives'] ?? []) as $eventId) {
                $nodesById[$eventId] = $this->buildEventNode($eventId, $this->resolveEventPayload($eventId, $graphEvents));
                $edgeKey = $eventId.'->'.$actorId;
                $edgesById[$edgeKey] = [
                    'from' => $eventId,
                    'to' => $actorId,
                ];
            }

            foreach ($this->normalizeEventNames($actor['sends'] ?? []) as $eventId) {
                $nodesById[$eventId] = $this->buildEventNode($eventId, $this->resolveEventPayload($eventId, $graphEvents));
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

        $graph = [
            'events' => $graphEvents,
            'actors' => $graphActors,
            'nodes' => array_values($nodesById),
            'edges' => array_values($edgesById),
        ];

        $flowRun->update([
            'graph_snapshot' => $graph,
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
     * @param  array<string, mixed>  $actor
     * @return array<string, mixed>
     */
    private function buildActorNode(string $actorId, array $actor): array
    {
        $node = [
            'id' => $actorId,
            'type' => 'actor',
            'label' => $actorId,
        ];

        if (is_int($actor['source_line'] ?? null) && $actor['source_line'] > 0) {
            $node['source_line'] = $actor['source_line'];
        }

        if (in_array($actor['source_kind'] ?? null, ['main', 'import'], true)) {
            $node['source_kind'] = $actor['source_kind'];
        }

        if (is_string($actor['source_module'] ?? null) && $actor['source_module'] !== '') {
            $node['source_module'] = $actor['source_module'];
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>|string  $event
     * @return array<string, mixed>
     */
    private function buildEventNode(string $eventId, array|string $event): array
    {
        $node = [
            'id' => $eventId,
            'type' => 'event',
            'label' => $eventId,
        ];

        if (! is_array($event)) {
            return $node;
        }

        if (is_int($event['source_line'] ?? null) && $event['source_line'] > 0) {
            $node['source_line'] = $event['source_line'];
        }

        if (in_array($event['source_kind'] ?? null, ['main', 'import'], true)) {
            $node['source_kind'] = $event['source_kind'];
        }

        if (is_string($event['source_module'] ?? null) && $event['source_module'] !== '') {
            $node['source_module'] = $event['source_module'];
        }

        return $node;
    }

    /**
     * @param  list<mixed>  $events
     * @return array<string, mixed>|string
     */
    private function resolveEventPayload(string $eventId, array $events): array|string
    {
        foreach ($events as $event) {
            if ($this->resolveEntityId($event) === $eventId) {
                return is_array($event) ? $event : $eventId;
            }
        }

        return $eventId;
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
