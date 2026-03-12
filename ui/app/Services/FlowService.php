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

            $this->markLockReady($flow, $run);
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

    public function markLockReady(Flow $flow, FlowRun $run): void
    {
        if ($run->type !== 'production') {
            return;
        }

        $this->writeDeploymentFiles($flow, $run);
        $run->update([
            'status' => 'ready',
        ]);
    }

    private function deployDevelopment(Flow $flow): array
    {
        $run = $this->createDeployment($flow, 'development', 'creating');
        $deploymentRoot = $this->deploymentRoot($flow, $run);
        $timezone = $flow->timezone ?? config('app.timezone', 'UTC');

        $payload = [
            'image' => $flow->image ?? 'flow:dev',
            'name' => sprintf('flow-%d-run-%d', $flow->id, $run->id),
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'flow_name' => $flow->name,
            'graph_hash' => $this->graphHash($flow),
            'test_run_id' => (string) config('services.flow_manager.test_run_id'),
            'labels' => [
                'kawaflow.flow_id' => (string) $flow->id,
                'kawaflow.flow_run_id' => (string) $run->id,
                'kawaflow.flow_name' => $flow->name,
                'kawaflow.graph_hash' => $this->graphHash($flow),
                'kawaflow.timezone' => $timezone,
            ],
            'environment' => [
                'FLOW_ID' => (string) $flow->id,
                'FLOW_RUN_ID' => (string) $run->id,
                'FLOW_TIMEZONE' => $timezone,
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
        $response = $this->client->createContainer($payload);

        if (! ($response['ok'] ?? false)) {
            $errorMessage = $this->resolveFlowManagerError($response, __('flows.run.error'));

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

            return [
                'ok' => false,
                'run_id' => $run->id,
                'message' => $errorMessage,
            ];
        }

        $responseData = is_array($response['data'] ?? null) ? $response['data'] : [];

        $run->logs()->create([
            'flow_id' => $flow->id,
            'flow_run_id' => $run->id,
            'level' => 'info',
            'message' => 'Development deployment requested.',
            'context' => $responseData,
        ]);

        return [
            'ok' => true,
            'run_id' => $run->id,
        ];
    }

    private function stopDeployment(Flow $flow, string $type): array
    {
        $run = $flow->activeRun($type);
        if (! $run) {
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
            $deadline = now()->addSeconds(5);
            while (! $containerId && now()->lt($deadline)) {
                usleep(200000);
                $run->refresh();
                $flow->refresh();
                $containerId = $run->container_id ?? $flow->container_id;
            }
        }
        if ($containerId) {
            $this->client->stopContainer($containerId);
        } else {
            $run->update([
                'active' => false,
                'status' => 'stopped',
                'finished_at' => now(),
            ]);

            if ($type === 'production') {
                $flow->update([
                    'status' => 'stopped',
                    'last_finished_at' => now(),
                ]);
            }
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
            $this->writeDeploymentFiles($flow, $run);
        }

        if ($type === 'production') {
            $flow->update([
                'status' => 'deploying',
                'last_started_at' => now(),
            ]);
        }

        return $run;
    }

    private function writeDeploymentFiles(Flow $flow, FlowRun $run): void
    {
        $root = $this->deploymentRoot($flow, $run);
        File::ensureDirectoryExists($root);

        if ($run->type === 'development') {
            File::put($root.'/flow.py', $flow->code ?? '');
            File::put($root.'/main.py', $this->developmentRuntimeScript());
        } else {
            File::put($root.'/main.py', $flow->code ?? '');
        }

        if ($run->type === 'production' && $run->lock) {
            File::put($root.'/uv.lock', $run->lock);
        }
    }

    private function deploymentRoot(Flow $flow, FlowRun $run): string
    {
        return storage_path(sprintf('app/flows/%d/%s/%d', $flow->id, $run->type, $run->id));
    }

    private function developmentRuntimeScript(): string
    {
        return <<<'PY'
import ast
import importlib.util
import inspect
import json
import socket
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from zoneinfo import ZoneInfo


SOCKET_PATH = '/run/kawaflow.sock'
FLOW_PATH = Path('/flow/flow.py')


def recv_exact(sock: socket.socket, size: int) -> bytes:
    chunks = bytearray()
    while len(chunks) < size:
        chunk = sock.recv(size - len(chunks))
        if not chunk:
            raise ConnectionError('socket closed while reading payload')
        chunks.extend(chunk)

    return bytes(chunks)


def receive_command() -> dict:
    while True:
        try:
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
                conn.connect(SOCKET_PATH)
                length = int.from_bytes(recv_exact(conn, 4), byteorder='big')
                payload = recv_exact(conn, length)
                return json.loads(payload.decode('utf-8'))
        except (
            FileNotFoundError,
            ConnectionRefusedError,
            ConnectionError,
            OSError,
            ValueError,
            json.JSONDecodeError,
        ):
            time.sleep(0.2)


def send_response(response: dict) -> None:
    encoded = json.dumps(response).encode('utf-8')
    deadline = time.time() + 2

    while True:
        try:
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
                conn.connect(SOCKET_PATH)
                conn.sendall(len(encoded).to_bytes(4, byteorder='big'))
                conn.sendall(encoded)
                return
        except OSError:
            if time.time() >= deadline:
                return
            time.sleep(0.1)


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
    prefix = 'CronEvent.by('
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


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def serialize_event_payload(event) -> dict:
    payload = {}
    event_dict = getattr(event, '__dict__', None)
    if isinstance(event_dict, dict):
        for key, value in event_dict.items():
            if isinstance(value, datetime):
                payload[key] = value.isoformat()
            elif isinstance(value, (str, int, float, bool)) or value is None:
                payload[key] = value
            else:
                payload[key] = str(value)

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

def process_cron_tick(data: dict) -> dict:
    try:
        from kawa import registry
        from kawa.core import EventFilter
        from kawa.cron import CronEvent
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

    pending_events = []

    try:
        registry.actors.clear()
        registry.events.clear()

        module_name = '_kawa_runtime_flow'
        spec = importlib.util.spec_from_file_location(module_name, FLOW_PATH)
        if spec is None or spec.loader is None:
            append_runtime_event(
                {
                    'kind': 'runtime_error',
                    'actor': 'runtime',
                    'trigger_event': 'cron_tick',
                    'event': 'cron_tick',
                    'payload': {'error': 'flow module could not be loaded'},
                }
            )
            return {'type': 'runtime_ack', 'command': 'cron_tick', 'ok': False}

        module = importlib.util.module_from_spec(spec)
        sys.modules[module_name] = module
        spec.loader.exec_module(module)
    except Exception as exc:
        append_runtime_event(
            {
                'kind': 'runtime_error',
                'actor': 'runtime',
                'trigger_event': 'cron_tick',
                'event': 'cron_tick',
                'payload': {'error': str(exc)},
            }
        )
        return {'type': 'runtime_ack', 'command': 'cron_tick', 'ok': False}

    for actor_definition in registry.actors.values():
        actor_name = actor_definition.name

        for receive_definition in actor_definition.receivs:
            event_filter = receive_definition.eventClassOrFilter
            if not isinstance(event_filter, EventFilter):
                continue

            if receive_definition.eventClass is not CronEvent:
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
                        'trigger_event': 'CronEvent',
                        'event': 'CronEvent',
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

            pending_events.append(CronEvent(template=template, datetime=tick_time))

    process_pending_events(pending_events, registry)

    return {
        'type': 'runtime_ack',
        'command': 'cron_tick',
        'ok': True,
    }


def handle_command(message: dict) -> dict:
    command = message.get('command', '')

    if command == 'dump':
        return extract_graph()

    if command == 'cron_tick':
        data = message.get('data') if isinstance(message.get('data'), dict) else {}
        return process_cron_tick(data)

    if command == 'pull_events':
        return {
            'type': 'runtime_events',
            'events': pull_runtime_events(),
        }

    return {'error': f'unknown command: {command}'}


def serve() -> None:
    while True:
        message = receive_command()
        response = handle_command(message)
        send_response(response)


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
