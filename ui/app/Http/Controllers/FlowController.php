<?php

namespace App\Http\Controllers;

use App\Http\Requests\Flows\FlowDeploymentsIndexRequest;
use App\Http\Requests\Flows\FlowStorageUpdateRequest;
use App\Models\Flow;
use App\Models\FlowHistory;
use App\Models\FlowRun;
use App\Models\FlowStorage;
use App\Services\FlowChatService;
use App\Services\FlowService;
use App\Services\FlowWebhookService;
use App\Support\FlowCodeDiff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class FlowController extends Controller
{
    public function __construct(
        private readonly FlowWebhookService $webhooks,
    ) {}

    private const EDITOR_DEPLOYMENTS_LIMIT = 5;

    private const DEFAULT_DEPLOYMENTS_LIMIT = 12;

    private const DEPLOYMENTS_PER_PAGE = 15;

    private const DEFAULT_TEMPLATE = 'cron';

    private const TEMPLATE_FILES = [
        'cron' => 'flow-templates/cron.py',
        'webhook' => 'flow-templates/webhook.py',
    ];

    public function index(Request $request): Response
    {
        $query = Flow::query()
            ->withCount('runs')
            ->forUser($request->user())
            ->latest();

        return Inertia::render('flows/Index', [
            'flows' => $query->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('flows/Create', [
            'defaultTemplate' => self::DEFAULT_TEMPLATE,
            'defaultTimezone' => config('app.timezone', 'UTC'),
            'timezoneOptions' => $this->timezoneOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $timezoneOptions = $this->timezoneOptions();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'template' => ['required', 'string', Rule::in($this->templateOptions())],
            'timezone' => ['nullable', 'string', 'timezone', Rule::in($timezoneOptions)],
        ]);

        $code = $this->templateCode($data['template']);

        $slug = Str::slug($data['name']) ?: Str::random(8);
        $suffix = 1;
        while (Flow::where('slug', $slug)->exists()) {
            $slug = Str::slug($data['name']).'-'.$suffix;
            $suffix++;
        }

        $flow = Flow::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'code' => $code,
            'code_updated_at' => now(),
            'user_id' => $request->user()->id,
            'status' => 'draft',
            'slug' => $slug,
            'timezone' => $data['timezone'] ?? config('app.timezone', 'UTC'),
        ]);

        return redirect()->route('flows.show', $flow)->with('success', __('flows.created'));
    }

    public function show(
        Request $request,
        Flow $flow,
        FlowChatService $flowChatService,
    ): Response {
        $flow->load(['user', 'activeChatConversation.messages', 'storages'])->loadCount('runs');

        $productionRun = $flow->activeRun('production');
        $activeDevelopmentRun = $flow->activeRun('development');
        $lastDevelopmentDeployment = $this->resolveLatestDeploymentOfType($flow, 'development');
        $productionLogsCount = $productionRun?->logs()->count() ?? 0;
        $history = $flow->histories()->latest()->limit(10)->get();
        $deployments = $this->buildDeployments($flow, self::EDITOR_DEPLOYMENTS_LIMIT);

        return Inertia::render('flows/Editor', [
            'mode' => 'edit',
            'flow' => $flow,
            'deployments' => $deployments,
            'productionRun' => $productionRun,
            'lastDevelopmentDeployment' => $lastDevelopmentDeployment,
            'webhookEndpoints' => $this->webhooks->editorEndpoints(
                $flow,
                $productionRun,
                $activeDevelopmentRun,
            ),
            'productionLogsCount' => $productionLogsCount,
            'status' => $flow->status,
            'runStats' => $this->runStats($flow),
            'history' => $history,
            'storage' => $this->buildStoragePayload($flow),
            'activeChat' => $flowChatService->activeChatPayload($flow),
            'pastChats' => $flowChatService->pastChatsPayload($flow),
            'timezoneOptions' => $this->timezoneOptions(),
            'permissions' => [
                'canRun' => $request->user()->can('run', $flow),
                'canUpdate' => $request->user()->can('update', $flow),
                'canDelete' => $request->user()->can('delete', $flow),
            ],
        ]);
    }

    public function deployments(FlowDeploymentsIndexRequest $request, Flow $flow): Response
    {
        $flow->load('user')->loadCount('runs');

        $filters = [
            'search' => $request->search(),
            'status' => $request->status(),
            'statuses' => $request->resolvedStatuses(),
            'type' => $request->runType(),
        ];

        $sorting = [
            'column' => $request->sortBy(),
            'direction' => $request->sortDirection(),
        ];

        return Inertia::render('flows/Deployments', [
            'flow' => $flow,
            'filters' => $filters,
            'sorting' => $sorting,
            'statusOptions' => FlowDeploymentsIndexRequest::statusOptions(),
            'deployments' => $this->buildPaginatedDeployments(
                $flow,
                self::DEPLOYMENTS_PER_PAGE,
                $filters,
                $sorting,
            ),
        ]);
    }

    public function deployment(Flow $flow, FlowRun $deployment): Response
    {
        abort_if($deployment->flow_id !== $flow->id, 404);

        $deploymentPayload = $this->buildDeploymentsFromRuns(
            $flow,
            collect([$deployment]),
        )[0] ?? null;

        abort_if(! is_array($deploymentPayload), 404);

        return Inertia::render('flows/Deployment', [
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
            ],
            'deployment' => $deploymentPayload,
        ]);
    }

    public function update(
        Request $request,
        Flow $flow,
        FlowCodeDiff $flowCodeDiff,
    ): RedirectResponse {
        $timezoneOptions = $this->timezoneOptions();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'code' => ['nullable', 'string'],
            'timezone' => ['sometimes', 'string', 'timezone', Rule::in($timezoneOptions)],
        ]);

        $codeChanged = array_key_exists('code', $data)
            && ($flow->code ?? '') !== ($data['code'] ?? '');

        if ($codeChanged) {
            FlowHistory::create([
                'flow_id' => $flow->id,
                'code' => $flow->code ?? '',
                'diff' => $flowCodeDiff->build($flow->code ?? '', $data['code'] ?? ''),
            ]);

            $data['code_updated_at'] = now();
        }

        $flow->update($data);

        return redirect()->route('flows.show', $flow)->with('success', __('flows.updated'));
    }

    public function updateStorage(FlowStorageUpdateRequest $request, Flow $flow): RedirectResponse
    {
        $environment = $request->environment();

        if ($flow->activeRun($environment) !== null) {
            return redirect()
                ->route('flows.show', $flow)
                ->with('error', __('flows.storage.error_active'));
        }

        $flow->storages()->updateOrCreate(
            ['environment' => $environment],
            ['content' => $request->content()],
        );

        return redirect()
            ->route('flows.show', $flow)
            ->with('success', __('flows.storage.updated'));
    }

    public function destroy(Request $request, Flow $flow, FlowService $flows): RedirectResponse
    {
        if ($flow->hasActiveDeploys()) {
            return redirect()
                ->route('flows.show', $flow)
                ->with('error', __('flows.delete.error_active'));
        }

        $flows->delete($flow);
        $flow->delete();

        return redirect()->route('flows.index')->with('success', __('flows.deleted'));
    }

    /**
     * @return array<int, array{status: string, total: int}>
     */
    private function runStats(Flow $flow): array
    {
        return $flow->runs()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get()
            ->map(static fn ($row) => [
                'status' => $row->status ?? 'unknown',
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDeployments(Flow $flow, int $limit = self::DEFAULT_DEPLOYMENTS_LIMIT): array
    {
        $runs = $flow->runs()
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->buildDeploymentsFromRuns($flow, $runs);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveLatestDeploymentOfType(Flow $flow, string $type): ?array
    {
        $run = $flow->runs()
            ->where('type', $type)
            ->latest('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $run instanceof FlowRun) {
            return null;
        }

        return $this->buildDeploymentsFromRuns($flow, collect([$run]))[0] ?? null;
    }

    /**
     * @param  array{search: ?string, status: ?string, statuses: array<int, string>, type: ?string}  $filters
     * @param  array{column: string, direction: string}  $sorting
     */
    private function buildPaginatedDeployments(Flow $flow, int $perPage, array $filters, array $sorting): LengthAwarePaginator
    {
        $search = $filters['search'];
        $statuses = $filters['statuses'];
        $type = $filters['type'];
        $sortColumn = $sorting['column'];
        $sortDirection = $sorting['direction'];

        $runs = $flow->runs()
            ->when($search !== null, function ($runsQuery) use ($search): void {
                $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

                $runsQuery->where(function ($whereQuery) use ($escapedSearch, $search): void {
                    $whereQuery->where('container_id', 'like', '%'.$escapedSearch.'%');

                    if (ctype_digit($search)) {
                        $whereQuery->orWhereKey((int) $search);
                    }
                });
            })
            ->when($statuses !== [], function ($runsQuery) use ($statuses): void {
                $runsQuery->whereIn('status', $statuses);
            })
            ->when($type !== null, function ($runsQuery) use ($type): void {
                $runsQuery->where('type', $type);
            })
            ->orderBy($sortColumn, $sortDirection)
            ->when($sortColumn !== 'id', function ($runsQuery) use ($sortDirection): void {
                $runsQuery->orderBy('id', $sortDirection);
            })
            ->paginate($perPage)
            ->withQueryString();

        $runs->setCollection(collect($this->buildDeploymentsFromRuns($flow, $runs->getCollection())));

        return $runs;
    }

    /**
     * @param  Collection<int, FlowRun>  $runs
     * @return array<int, array<string, mixed>>
     */
    private function buildDeploymentsFromRuns(Flow $flow, Collection $runs): array
    {
        if ($runs->isEmpty()) {
            return [];
        }

        $historyTimeline = $flow->histories()
            ->orderBy('created_at')
            ->get();

        $runIds = $runs->pluck('id')->all();
        $logsByRunId = $flow->logs()
            ->whereIn('flow_run_id', $runIds)
            ->latest('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('flow_run_id');

        return $runs->map(function (FlowRun $run) use ($flow, $historyTimeline, $logsByRunId): array {
            $runLogs = ($logsByRunId->get($run->id) ?? collect())
                ->take(50)
                ->values();
            $codeSnapshot = $this->resolveRunCodeSnapshot($run, $flow, $historyTimeline);

            return [
                'id' => $run->id,
                'type' => $run->type,
                'active' => (bool) $run->active,
                'status' => $run->status,
                'container_id' => $run->container_id,
                'lock' => $run->lock,
                'actors' => $run->actors,
                'events' => $run->events,
                'meta' => $run->meta,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'created_at' => $run->created_at,
                'updated_at' => $run->updated_at,
                'code' => $codeSnapshot,
                'graph' => $this->resolveRunGraphSnapshot($run),
                'webhooks' => $this->webhooks->deploymentEndpoints($flow, $run, $codeSnapshot),
                'logs' => $runLogs
                    ->map(static fn ($log) => [
                        'id' => $log->id,
                        'level' => $log->level,
                        'message' => $log->message,
                        'node_key' => $log->node_key,
                        'context' => $log->context,
                        'created_at' => $log->created_at,
                    ])
                    ->all(),
            ];
        })->values()->all();
    }

    /**
     * @return array{development: array<mixed>, production: array<mixed>}
     */
    private function buildStoragePayload(Flow $flow): array
    {
        return [
            'development' => $this->resolveStorageContent($flow->storageForEnvironment('development')),
            'production' => $this->resolveStorageContent($flow->storageForEnvironment('production')),
        ];
    }

    /**
     * @return array<mixed>
     */
    private function resolveStorageContent(?FlowStorage $storage): array
    {
        return is_array($storage?->content) ? $storage->content : [];
    }

    private function resolveRunCodeSnapshot(FlowRun $run, Flow $flow, Collection $historyTimeline): string
    {
        if (is_string($run->code_snapshot) && $run->code_snapshot !== '') {
            return $run->code_snapshot;
        }

        $runMoment = $run->started_at ?? $run->created_at;

        if (! $runMoment) {
            return $flow->code ?? '';
        }

        $nextHistory = $historyTimeline->first(
            static fn (FlowHistory $history): bool => $history->created_at !== null
                && $history->created_at->greaterThan($runMoment),
        );

        if ($nextHistory instanceof FlowHistory) {
            return $nextHistory->code ?? '';
        }

        return $flow->code ?? '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRunGraphSnapshot(FlowRun $run): ?array
    {
        if (is_array($run->graph_snapshot) && $run->graph_snapshot !== []) {
            return $run->graph_snapshot;
        }

        return $this->buildGraphFromRuntimeData($run->events, $run->actors);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildGraphFromRuntimeData(mixed $events, mixed $actors): ?array
    {
        if (! is_array($events) && ! is_array($actors)) {
            return null;
        }

        $graphEvents = is_array($events) ? $events : [];
        $graphActors = is_array($actors) ? $actors : [];
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

            $receives = $actor['receives'] ?? [];
            if (is_string($receives)) {
                $receives = [$receives];
            }

            foreach ($receives as $receive) {
                $eventId = $this->resolveEntityId($receive);

                if ($eventId === null) {
                    continue;
                }

                $nodesById[$eventId] = $this->buildEventNode($eventId, $this->resolveEventPayload($eventId, $graphEvents));

                $edgeKey = $eventId.'->'.$actorId;
                $edgesById[$edgeKey] = [
                    'from' => $eventId,
                    'to' => $actorId,
                ];
            }

            $sends = $actor['sends'] ?? [];
            if (is_string($sends)) {
                $sends = [$sends];
            }

            foreach ($sends as $send) {
                $eventId = $this->resolveEntityId($send);

                if ($eventId === null) {
                    continue;
                }

                $nodesById[$eventId] = $this->buildEventNode($eventId, $this->resolveEventPayload($eventId, $graphEvents));

                $edgeKey = $actorId.'->'.$eventId;
                $edgesById[$edgeKey] = [
                    'from' => $actorId,
                    'to' => $eventId,
                ];
            }
        }

        if ($nodesById === [] && $edgesById === []) {
            return null;
        }

        return [
            'events' => $graphEvents,
            'actors' => $graphActors,
            'nodes' => array_values($nodesById),
            'edges' => array_values($edgesById),
        ];
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
     * @return array<int, string>
     */
    private function timezoneOptions(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    /**
     * @return array<int, string>
     */
    private function templateOptions(): array
    {
        return ['blank', ...array_keys(self::TEMPLATE_FILES)];
    }

    private function templateCode(string $template): string
    {
        if ($template === 'blank') {
            return '';
        }

        $relativePath = self::TEMPLATE_FILES[$template] ?? null;

        if (! is_string($relativePath)) {
            throw new RuntimeException("Unknown flow template [{$template}].");
        }

        $path = resource_path($relativePath);

        if (! File::exists($path)) {
            throw new RuntimeException("Flow template file [{$relativePath}] not found.");
        }

        return File::get($path);
    }
}
