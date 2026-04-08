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
            'code' => $flow->code ?? '',
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
                '/app/.venv/bin/python',
                '-u',
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
        File::put($root.'/main.py', $this->runtimeScript());

        if ($run->type === 'production' && $run->lock) {
            File::put($root.'/uv.lock', $run->lock);
        }
    }

    private function deploymentRoot(Flow $flow, FlowRun $run): string
    {
        return storage_path(sprintf('app/flows/%d/%s/%d', $flow->id, $run->type, $run->id));
    }

    private function runtimeScript(): string
    {
        return <<<'PY'
import ast
import copy
import importlib.util
import inspect
import json
import os
import socket
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from pydash import get as pydash_get
from pydash import set_ as pydash_set
from pydash import unset as pydash_unset
from zoneinfo import ZoneInfo


SOCKET_PATH = '/run/kawaflow.sock'
FLOW_PATH = Path(os.getenv('FLOW_PATH', '/flow/flow.py'))
STORAGE_MISSING = object()


def runtime_metadata() -> dict:
    metadata = {}

    flow_id = os.getenv('FLOW_ID')
    if flow_id:
        metadata['flow_id'] = flow_id

    flow_run_id = os.getenv('FLOW_RUN_ID')
    if flow_run_id:
        metadata['flow_run_id'] = flow_run_id

    environment = os.getenv('FLOW_ENVIRONMENT')
    if environment:
        metadata['environment'] = environment

    return metadata


def load_runtime_storage():
    raw_storage = str(os.getenv('FLOW_STORAGE') or '').strip()
    if raw_storage == '':
        return {}

    try:
        decoded_storage = json.loads(raw_storage)
    except json.JSONDecodeError:
        return {}

    if isinstance(decoded_storage, (dict, list)):
        return decoded_storage

    return {}


def mark_runtime_storage_dirty() -> None:
    global RUNTIME_STORAGE_DIRTY

    RUNTIME_STORAGE_DIRTY = True


class RuntimeStorage:
    def __init__(self, data, on_change):
        self._data = data
        self._on_change = on_change

    def get(self, key: str, default=None):
        value = pydash_get(self._data, key, STORAGE_MISSING)
        if value is STORAGE_MISSING:
            return default

        return copy.deepcopy(value)

    def set(self, key: str, value) -> None:
        pydash_set(self._data, key, copy.deepcopy(value))
        self._on_change()

    def delete(self, key: str) -> None:
        pydash_unset(self._data, key)
        self._on_change()


def recv_exact(sock: socket.socket, size: int) -> bytes:
    chunks = bytearray()
    while len(chunks) < size:
        chunk = sock.recv(size - len(chunks))
        if not chunk:
            raise ConnectionError('socket closed while reading payload')
        chunks.extend(chunk)

    return bytes(chunks)


def recv_message(conn: socket.socket) -> dict:
    length = int.from_bytes(recv_exact(conn, 4), byteorder='big')
    payload = recv_exact(conn, length)
    return json.loads(payload.decode('utf-8'))


def send_message(conn: socket.socket, message: dict) -> None:
    encoded = json.dumps(message).encode('utf-8')
    conn.sendall(len(encoded).to_bytes(4, byteorder='big'))
    conn.sendall(encoded)


def connect_to_manager() -> socket.socket:
    while True:
        try:
            conn = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            conn.connect(SOCKET_PATH)
            send_message(conn, {'type': 'runtime_hello', **runtime_metadata()})
            return conn
        except (
            FileNotFoundError,
            ConnectionRefusedError,
            ConnectionError,
            OSError,
        ):
            time.sleep(0.2)


def normalize_receive(value: ast.AST):
    if isinstance(value, ast.Tuple):
        items = []
        for item in value.elts:
            normalized = normalize_receive(item)
            if isinstance(normalized, list):
                items.extend(normalized)
            else:
                items.append(normalized)
        return items

    if isinstance(value, ast.Name):
        return value.id

    if isinstance(value, ast.Attribute):
        base = normalize_receive(value.value)
        if isinstance(base, str) and base:
            return f"{base}.{value.attr}"

        return value.attr

    if isinstance(value, ast.Call):
        base = normalize_receive(value.func)
        parts = []
        for arg in value.args:
            if isinstance(arg, ast.Constant):
                parts.append(str(arg.value))
            else:
                parts.append(ast.unparse(arg))
        return f"{base}({', '.join(parts)})"

    return ast.unparse(value)


def is_event_decorator(node: ast.AST) -> bool:
    return isinstance(node, ast.Name) and node.id == 'event'


def is_actor_decorator(node: ast.AST) -> bool:
    if isinstance(node, ast.Name):
        return node.id == 'actor'

    if isinstance(node, ast.Call) and isinstance(node.func, ast.Name):
        return node.func.id == 'actor'

    return False


def extract_graph() -> dict:
    with open(FLOW_PATH, 'r', encoding='utf-8') as flow_file:
        tree = ast.parse(flow_file.read(), FLOW_PATH)

    def register_import(node: ast.AST, imported_names: dict[str, dict[str, object | None]]) -> None:
        if isinstance(node, ast.Import):
            for alias in node.names:
                local_name = alias.asname or alias.name.split('.', 1)[0]
                imported_names[local_name] = {
                    'source_line': node.lineno,
                    'source_kind': 'import',
                    'source_module': alias.name,
                }
            return

        if isinstance(node, ast.ImportFrom):
            module_name = node.module or ''
            for alias in node.names:
                if alias.name == '*':
                    continue

                local_name = alias.asname or alias.name
                imported_names[local_name] = {
                    'source_line': node.lineno,
                    'source_kind': 'import',
                    'source_module': module_name,
                }

    def resolve_imported_source(
        name: str,
        imported_names: dict[str, dict[str, object | None]],
    ) -> dict[str, object | None] | None:
        imported_source = imported_names.get(name)
        if imported_source is not None:
            return dict(imported_source)

        root_name = name.split('.', 1)[0].split('(', 1)[0]
        imported_source = imported_names.get(root_name)
        if imported_source is not None:
            return dict(imported_source)

        return None

    def resolve_main_source(line_number: int) -> dict[str, object | None]:
        return {
            'source_line': line_number,
            'source_kind': 'main',
            'source_module': None,
        }

    def resolve_actor_source(
        node: ast.FunctionDef | ast.ClassDef,
        imported_names: dict[str, dict[str, object | None]],
    ) -> dict[str, object | None]:
        imported_source = resolve_imported_source(node.name, imported_names)
        if imported_source is not None:
            return imported_source

        return resolve_main_source(node.lineno)

    def resolve_event_source(
        event_name: str,
        event_sources: dict[str, dict[str, object | None]],
        imported_names: dict[str, dict[str, object | None]],
    ) -> dict[str, object | None]:
        event_source = event_sources.get(event_name)
        if event_source is not None:
            return dict(event_source)

        imported_source = resolve_imported_source(event_name, imported_names)
        if imported_source is not None:
            return imported_source

        return {}

    def append_event(
        event_name: str,
        event_ids: set[str],
        event_sources: dict[str, dict[str, object | None]],
        imported_names: dict[str, dict[str, object | None]],
        events: list[dict[str, object | None]],
        nodes: list[dict[str, object | None]],
    ) -> None:
        if event_name in event_ids:
            return

        source = resolve_event_source(event_name, event_sources, imported_names)
        event_payload = {'id': event_name, 'name': event_name, **source}
        events.append(event_payload)
        nodes.append({'id': event_name, 'type': 'event', 'label': event_name, **source})
        event_ids.add(event_name)

    events = []
    actors = []
    nodes = []
    edges = []
    event_ids = set()
    imported_names = {}
    event_sources = {}

    for node in tree.body:
        register_import(node, imported_names)

        if isinstance(node, ast.ClassDef):
            if any(is_event_decorator(decorator) for decorator in node.decorator_list):
                event_sources[node.name] = resolve_main_source(node.lineno)

    for node in tree.body:
        if isinstance(node, ast.ClassDef):
            if any(is_event_decorator(decorator) for decorator in node.decorator_list):
                append_event(
                    node.name,
                    event_ids,
                    event_sources,
                    imported_names,
                    events,
                    nodes,
                )

        if isinstance(node, (ast.FunctionDef, ast.ClassDef)):
            actor_decorators = [
                decorator for decorator in node.decorator_list if is_actor_decorator(decorator)
            ]
            if not actor_decorators:
                continue

            receives = []
            sends = []

            for decorator in actor_decorators:
                if not isinstance(decorator, ast.Call):
                    continue

                for keyword in decorator.keywords:
                    if keyword.arg in ('receivs', 'receives'):
                        normalized = normalize_receive(keyword.value)
                        if isinstance(normalized, list):
                            receives.extend(normalized)
                        else:
                            receives.append(normalized)

                    if keyword.arg == 'sends':
                        normalized = normalize_receive(keyword.value)
                        if isinstance(normalized, list):
                            sends.extend(normalized)
                        else:
                            sends.append(normalized)

            actors.append(
                {
                    'id': node.name,
                    'name': node.name,
                    'receives': receives,
                    'sends': sends,
                    **resolve_actor_source(node, imported_names),
                }
            )
            nodes.append(
                {
                    'id': node.name,
                    'type': 'actor',
                    'label': node.name,
                    **resolve_actor_source(node, imported_names),
                }
            )

            for receive in receives:
                receive_name = str(receive)

                append_event(
                    receive_name,
                    event_ids,
                    event_sources,
                    imported_names,
                    events,
                    nodes,
                )

                edges.append({'from': receive_name, 'to': node.name})

            for send in sends:
                append_event(
                    str(send),
                    event_ids,
                    event_sources,
                    imported_names,
                    events,
                    nodes,
                )

    return {
        'events': events,
        'actors': actors,
        'nodes': nodes,
        'edges': edges,
    }


def cron_field_matches(field: str, value: int, minimum: int, maximum: int) -> bool:
    parts = [part.strip() for part in field.split(',') if part.strip()]
    if not parts:
        return False

    for part in parts:
        step = 1
        base = part

        if '/' in part:
            base, step_text = part.split('/', 1)
            try:
                step = int(step_text)
            except ValueError:
                return False

            if step <= 0:
                return False

        if base == '*':
            start = minimum
            end = maximum
        elif '-' in base:
            start_text, end_text = base.split('-', 1)
            try:
                start = int(start_text)
                end = int(end_text)
            except ValueError:
                return False
        else:
            try:
                start = int(base)
                end = start
            except ValueError:
                return False

        if start < minimum or end > maximum or start > end:
            return False

        if value < start or value > end:
            continue

        if (value - start) % step == 0:
            return True

    return False


def cron_template_matches(template: str, timestamp: datetime) -> bool:
    parts = [part for part in template.split() if part]
    if len(parts) == 6:
        parts = parts[1:]

    if len(parts) != 5:
        return False

    minute_field, hour_field, day_field, month_field, weekday_field = parts
    cron_weekday = (timestamp.weekday() + 1) % 7

    minute_match = cron_field_matches(minute_field, timestamp.minute, 0, 59)
    hour_match = cron_field_matches(hour_field, timestamp.hour, 0, 23)
    month_match = cron_field_matches(month_field, timestamp.month, 1, 12)
    day_of_month_match = cron_field_matches(day_field, timestamp.day, 1, 31)
    day_of_week_match = cron_field_matches(weekday_field, cron_weekday, 0, 7)

    if not (minute_match and hour_match and month_match):
        return False

    if day_field != '*' and weekday_field != '*':
        return day_of_month_match or day_of_week_match

    return day_of_month_match and day_of_week_match


def parse_cron_template(receive: str) -> str | None:
    normalized = receive.strip()
    prefix = 'Cron.by('
    if not normalized.startswith(prefix) or not normalized.endswith(')'):
        return None

    template = normalized[len(prefix):-1].strip().strip('"\'')
    if not template:
        return None

    return template


def resolve_tick_time(timezone_name: str, timestamp_value: object) -> datetime:
    zone = ZoneInfo(timezone_name)
    if isinstance(timestamp_value, str) and timestamp_value.strip():
        parsed = datetime.fromisoformat(timestamp_value.strip())
        if parsed.tzinfo is None:
            return parsed.replace(tzinfo=zone)
        return parsed.astimezone(zone)

    return datetime.now(zone)


class RuntimeContext:
    def __init__(self, pending_events: list, actor_name: str, trigger_event: str):
        self._pending_events = pending_events
        self._actor_name = actor_name
        self._trigger_event = trigger_event
        self.storage = RUNTIME_SHARED_STORAGE

    def dispatch(self, event):
        event_name = event.__class__.__name__
        self._pending_events.append(event)
        append_runtime_event(
            {
                'kind': 'event_dispatched',
                'actor': self._actor_name,
                'trigger_event': self._trigger_event,
                'event': event_name,
                'payload': serialize_event_payload(event),
            }
        )


RUNTIME_EVENTS = []
RUNTIME_EVENT_SEQUENCE = 0
RUNTIME_STORAGE = load_runtime_storage()
RUNTIME_STORAGE_DIRTY = False
RUNTIME_SHARED_STORAGE = RuntimeStorage(RUNTIME_STORAGE, mark_runtime_storage_dirty)


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def normalize_payload_value(value):
    if isinstance(value, datetime):
        return value.isoformat()

    if isinstance(value, dict):
        normalized = {}
        for key, item in value.items():
            normalized[str(key)] = normalize_payload_value(item)
        return normalized

    if isinstance(value, (list, tuple)):
        return [normalize_payload_value(item) for item in value]

    if isinstance(value, (str, int, float, bool)) or value is None:
        return value

    return str(value)


def serialize_event_payload(event) -> dict:
    payload = {}
    event_dict = getattr(event, '__dict__', None)
    if isinstance(event_dict, dict):
        for key, value in event_dict.items():
            payload[key] = normalize_payload_value(value)

    return payload


def append_runtime_event(event: dict) -> None:
    global RUNTIME_EVENT_SEQUENCE

    RUNTIME_EVENT_SEQUENCE += 1
    runtime_event = {
        'seq': RUNTIME_EVENT_SEQUENCE,
        'timestamp': now_iso(),
        **event,
    }
    RUNTIME_EVENTS.append(runtime_event)


def pull_runtime_events() -> list:
    events = list(RUNTIME_EVENTS)
    RUNTIME_EVENTS.clear()
    return events


def pull_runtime_storage():
    global RUNTIME_STORAGE_DIRTY

    if not RUNTIME_STORAGE_DIRTY:
        return None

    RUNTIME_STORAGE_DIRTY = False
    return copy.deepcopy(RUNTIME_STORAGE)


def receive_definition_matches_event(receive_definition, incoming_event) -> bool:
    try:
        event_filter = receive_definition.eventClassOrFilter
    except Exception:
        return False

    if event_filter is None:
        return False

    if hasattr(event_filter, 'filter_function'):
        try:
            return bool(event_filter(incoming_event))
        except Exception:
            return False

    try:
        event_class = receive_definition.eventClass
        return isinstance(incoming_event, event_class)
    except Exception:
        return False


def invoke_actor(actor_definition, incoming_event, pending_events: list) -> None:
    actor_name = actor_definition.name
    trigger_event_name = incoming_event.__class__.__name__

    append_runtime_event(
        {
            'kind': 'actor_invoked',
            'actor': actor_name,
            'trigger_event': trigger_event_name,
            'event': trigger_event_name,
            'payload': serialize_event_payload(incoming_event),
        }
    )

    actor_callable = actor_definition.actorFuncOrClass
    if inspect.isclass(actor_callable):
        actor_callable = actor_callable()

    runtime_context = RuntimeContext(
        pending_events,
        actor_name=actor_name,
        trigger_event=trigger_event_name,
    )

    result = actor_callable(runtime_context, incoming_event)
    if inspect.isawaitable(result):
        return


def process_pending_events(pending_events: list, registry) -> None:
    while pending_events:
        incoming_event = pending_events.pop(0)

        for actor_definition in registry.actors.values():
            if not any(
                receive_definition_matches_event(receive_definition, incoming_event)
                for receive_definition in actor_definition.receivs
            ):
                continue

            try:
                invoke_actor(actor_definition, incoming_event, pending_events)
            except Exception as exc:
                append_runtime_event(
                    {
                        'kind': 'actor_error',
                        'actor': actor_definition.name,
                        'trigger_event': incoming_event.__class__.__name__,
                        'event': incoming_event.__class__.__name__,
                        'payload': {'error': str(exc)},
                    }
                )


def load_flow_registry(trigger_event: str, event_name: str):
    try:
        from kawa import registry
    except Exception as exc:
        append_runtime_event(
            {
                'kind': 'runtime_error',
                'actor': 'runtime',
                'trigger_event': trigger_event,
                'event': event_name,
                'payload': {'error': f'kawa import failed: {exc}'},
            }
        )
        return None

    try:
        registry.actors.clear()
        registry.events.clear()

        module_name = '_kawa_runtime_flow'
        sys.modules.pop(module_name, None)
        spec = importlib.util.spec_from_file_location(module_name, FLOW_PATH)
        if spec is None or spec.loader is None:
            append_runtime_event(
                {
                    'kind': 'runtime_error',
                    'actor': 'runtime',
                    'trigger_event': trigger_event,
                    'event': event_name,
                    'payload': {'error': 'flow module could not be loaded'},
                }
            )
            return None

        module = importlib.util.module_from_spec(spec)
        sys.modules[module_name] = module
        spec.loader.exec_module(module)
        return registry
    except Exception as exc:
        append_runtime_event(
            {
                'kind': 'runtime_error',
                'actor': 'runtime',
                'trigger_event': trigger_event,
                'event': event_name,
                'payload': {'error': str(exc)},
            }
        )
        return None

def process_cron_tick(data: dict) -> dict:
    try:
        from kawa import Cron
        from kawa.core import EventFilter
    except Exception as exc:
        append_runtime_event(
            {
                'kind': 'runtime_error',
                'actor': 'runtime',
                'trigger_event': 'cron_tick',
                'event': 'cron_tick',
                'payload': {'error': f'kawa import failed: {exc}'},
            }
        )
        return {'type': 'runtime_ack', 'command': 'cron_tick', 'ok': False}

    timezone_name = str(data.get('timezone') or 'UTC')

    try:
        tick_time = resolve_tick_time(timezone_name, data.get('timestamp'))
    except Exception:
        timezone_name = 'UTC'
        tick_time = datetime.now(ZoneInfo(timezone_name))

    registry = load_flow_registry('cron_tick', 'cron_tick')
    if registry is None:
        return {'type': 'runtime_ack', 'command': 'cron_tick', 'ok': False}

    pending_events = []

    for actor_definition in registry.actors.values():
        actor_name = actor_definition.name

        for receive_definition in actor_definition.receivs:
            event_filter = receive_definition.eventClassOrFilter
            if not isinstance(event_filter, EventFilter):
                continue

            if receive_definition.eventClass is not Cron:
                continue

            template = str(event_filter.context.get('template') or '').strip()
            if not template:
                continue

            try:
                matches = cron_template_matches(template, tick_time)
            except Exception:
                append_runtime_event(
                    {
                        'kind': 'cron_template_error',
                        'actor': actor_name,
                        'trigger_event': 'Cron',
                        'event': 'Cron',
                        'payload': {
                            'timezone': timezone_name,
                            'datetime': tick_time.isoformat(),
                            'template': template,
                        },
                    }
                )
                continue

            if not matches:
                continue

            pending_events.append(Cron(template=template, datetime=tick_time))

    process_pending_events(pending_events, registry)

    return {
        'type': 'runtime_ack',
        'command': 'cron_tick',
        'ok': True,
    }


def process_webhook(data: dict) -> dict:
    try:
        from kawa.webhook import Webhook
    except Exception as exc:
        append_runtime_event(
            {
                'kind': 'runtime_error',
                'actor': 'runtime',
                'trigger_event': 'webhook',
                'event': 'Webhook',
                'payload': {'error': f'kawa import failed: {exc}'},
            }
        )
        return {'type': 'runtime_ack', 'command': 'webhook', 'ok': False}

    slug = data.get('slug')
    if not isinstance(slug, str) or not slug.strip():
        append_runtime_event(
            {
                'kind': 'runtime_error',
                'actor': 'runtime',
                'trigger_event': 'webhook',
                'event': 'Webhook',
                'payload': {'error': 'webhook slug is required'},
            }
        )
        return {'type': 'runtime_ack', 'command': 'webhook', 'ok': False}

    registry = load_flow_registry('webhook', 'Webhook')
    if registry is None:
        return {'type': 'runtime_ack', 'command': 'webhook', 'ok': False}

    pending_events = [Webhook(slug=slug.strip(), payload=data.get('payload'))]
    process_pending_events(pending_events, registry)

    return {
        'type': 'runtime_ack',
        'command': 'webhook',
        'ok': True,
    }


def handle_command(message: dict) -> dict:
    command = message.get('command', '')

    if command == 'dump':
        return {
            'type': 'runtime_graph',
            'graph': extract_graph(),
            **runtime_metadata(),
        }

    if command == 'cron_tick':
        data = message.get('data') if isinstance(message.get('data'), dict) else {}
        return process_cron_tick(data)

    if command == 'webhook':
        data = message.get('data') if isinstance(message.get('data'), dict) else {}
        return process_webhook(data)

    return {'error': f'unknown command: {command}'}


def flush_runtime_events(conn: socket.socket) -> None:
    events = pull_runtime_events()
    if not events:
        return

    send_message(
        conn,
        {
            'type': 'runtime_events',
            'events': events,
        }
    )


def flush_runtime_storage(conn: socket.socket) -> None:
    storage = pull_runtime_storage()
    if storage is None:
        return

    send_message(
        conn,
        {
            'type': 'runtime_storage',
            'storage': storage,
            **runtime_metadata(),
        }
    )


def serve_connection(conn: socket.socket) -> None:
    while True:
        message = recv_message(conn)
        response = handle_command(message)
        send_message(conn, response)
        flush_runtime_events(conn)
        flush_runtime_storage(conn)


def serve() -> None:
    while True:
        conn = connect_to_manager()
        try:
            flush_runtime_events(conn)
            flush_runtime_storage(conn)
            serve_connection(conn)
        except (
            ConnectionError,
            OSError,
            ValueError,
            json.JSONDecodeError,
        ):
            time.sleep(0.2)
        finally:
            conn.close()


if __name__ == '__main__':
    serve()
PY;
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
