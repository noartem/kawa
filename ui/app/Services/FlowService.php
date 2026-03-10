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
        $run = $this->createDeployment($flow, 'development', 'running');
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
            'active' => false,
            'status' => 'stopped',
            'finished_at' => now(),
        ]);

        if ($type === 'production' && $flow->status === 'running') {
            $flow->update([
                'status' => 'stopped',
                'last_finished_at' => now(),
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
            'graph_snapshot' => $flow->graph,
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
from datetime import datetime
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

    events = []
    actors = []
    nodes = []
    edges = []
    event_ids = set()

    for node in tree.body:
        if isinstance(node, ast.ClassDef):
            if any(is_event_decorator(decorator) for decorator in node.decorator_list):
                events.append({'id': node.name, 'name': node.name})
                nodes.append({'id': node.name, 'type': 'event', 'label': node.name})
                event_ids.add(node.name)

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
                }
            )
            nodes.append({'id': node.name, 'type': 'actor', 'label': node.name})

            for receive in receives:
                receive_name = str(receive)

                if receive_name not in event_ids:
                    events.append({'id': receive_name, 'name': receive_name})
                    nodes.append(
                        {
                            'id': receive_name,
                            'type': 'event',
                            'label': receive_name,
                        }
                    )
                    event_ids.add(receive_name)

                edges.append({'from': receive_name, 'to': node.name})

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


def process_cron_tick(data: dict) -> dict:
    try:
        from kawa import Context, registry
        from kawa.core import EventFilter
        from kawa.cron import CronEvent
    except Exception as exc:
        return {
            'type': 'cron_tick_result',
            'timezone': str(data.get('timezone') or 'UTC'),
            'dispatches': [],
            'invalid_templates': [],
            'error': f'kawa import failed: {exc}',
        }

    timezone_name = str(data.get('timezone') or 'UTC')

    try:
        tick_time = resolve_tick_time(timezone_name, data.get('timestamp'))
    except Exception:
        timezone_name = 'UTC'
        tick_time = datetime.now(ZoneInfo(timezone_name))

    dispatches = []
    invalid_templates = []

    try:
        registry.actors.clear()
        registry.events.clear()

        module_name = '_kawa_runtime_flow'
        spec = importlib.util.spec_from_file_location(module_name, FLOW_PATH)
        if spec is None or spec.loader is None:
            return {
                'type': 'cron_tick_result',
                'timezone': timezone_name,
                'dispatches': [],
                'invalid_templates': [],
                'error': 'flow module could not be loaded',
            }

        module = importlib.util.module_from_spec(spec)
        sys.modules[module_name] = module
        spec.loader.exec_module(module)
    except Exception as exc:
        return {
            'type': 'cron_tick_result',
            'timezone': timezone_name,
            'dispatches': [],
            'invalid_templates': [],
            'error': str(exc),
        }

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
                invalid_templates.append(
                    {
                        'actor': actor_name,
                        'template': template,
                    }
                )
                continue

            if not matches:
                continue

            event = CronEvent(template=template, datetime=tick_time)

            try:
                actor_callable = actor_definition.actorFuncOrClass
                if inspect.isclass(actor_callable):
                    actor_callable = actor_callable()

                result = actor_callable(Context(), event)
                if inspect.isawaitable(result):
                    continue
            except Exception:
                continue

            dispatches.append(
                {
                    'actor': actor_name,
                    'event': 'CronEvent',
                    'template': template,
                    'datetime': tick_time.isoformat(),
                }
            )

    return {
        'type': 'cron_tick_result',
        'timezone': timezone_name,
        'dispatches': dispatches,
        'invalid_templates': invalid_templates,
    }


def handle_command(message: dict) -> dict:
    command = message.get('command', '')

    if command == 'dump':
        return extract_graph()

    if command == 'cron_tick':
        data = message.get('data') if isinstance(message.get('data'), dict) else {}
        return process_cron_tick(data)

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
        $graph = $flow->graph ?? [];
        $encodedGraph = json_encode($graph, JSON_THROW_ON_ERROR);

        return hash('sha256', ($flow->code ?? '').'::'.$encodedGraph);
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
