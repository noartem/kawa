<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\FlowRun;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;

final class FlowWebhookService
{
    private const DEVELOPMENT_URL_TTL_MINUTES = 1440;

    /**
     * @var array<string, list<array{slug: string, source_line: int|null}>>
     */
    private array $codeWebhookCache = [];

    private ?DateTimeInterface $developmentUrlExpiresAt = null;

    /**
     * @return list<array{slug: string, source_line: int|null, production_url: string|null, development_url: string|null}>
     */
    public function editorEndpoints(
        Flow $flow,
        ?FlowRun $productionRun,
        ?FlowRun $developmentRun,
    ): array {
        $endpointsBySlug = [];

        if ($developmentRun instanceof FlowRun) {
            $this->mergeEndpoints(
                $endpointsBySlug,
                $this->deploymentEndpoints($flow, $developmentRun),
            );
        }

        if ($productionRun instanceof FlowRun) {
            $this->mergeEndpoints(
                $endpointsBySlug,
                $this->deploymentEndpoints($flow, $productionRun),
            );
        }

        return array_values($endpointsBySlug);
    }

    /**
     * @return list<array{slug: string, source_line: int|null, production_url: string|null, development_url: string|null}>
     */
    public function deploymentEndpoints(
        Flow $flow,
        FlowRun $run,
        ?string $code = null,
    ): array {
        $declarations = $this->runWebhooks($flow, $run, $code);
        $containerId = $this->resolveContainerId($flow, $run);

        return array_values(array_map(function (array $declaration) use ($containerId, $flow, $run): array {
            $isProduction = $run->type === 'production';
            $isReachable = (bool) $run->active && $containerId !== null;

            return [
                'slug' => $declaration['slug'],
                'source_line' => $declaration['source_line'],
                'production_url' => $isProduction && $isReachable
                     ? URL::signedRoute('webhooks.production.show', [
                         'flow' => $flow,
                         'slug' => $declaration['slug'],
                     ])
                     : null,
                'development_url' => ! $isProduction && $isReachable
                    ? URL::temporarySignedRoute(
                        'webhooks.development.show',
                        $this->developmentUrlExpiresAt(),
                        [
                            'flow' => $flow,
                            'run' => $run->id,
                            'slug' => $declaration['slug'],
                        ],
                    )
                    : null,
            ];
        }, $declarations));
    }

    public function resolveProductionRun(Flow $flow, string $slug): ?FlowRun
    {
        $run = $flow->activeRun('production');

        if (! $run instanceof FlowRun || ! $run->active) {
            return null;
        }

        if (! $this->runHasWebhook($flow, $run, $slug)) {
            return null;
        }

        return $run;
    }

    public function resolveDevelopmentRun(
        Flow $flow,
        string $slug,
        ?int $expectedRunId = null,
    ): ?FlowRun {
        $run = $flow->activeRun('development');

        if (! $run instanceof FlowRun || ! $run->active) {
            return null;
        }

        if ($expectedRunId !== null && $run->id !== $expectedRunId) {
            return null;
        }

        if (! $this->runHasWebhook($flow, $run, $slug)) {
            return null;
        }

        return $run;
    }

    public function resolveContainerId(Flow $flow, FlowRun $run): ?string
    {
        $containerId = $run->container_id;
        if (is_string($containerId) && $containerId !== '') {
            return $containerId;
        }

        if ($run->type !== 'production') {
            return null;
        }

        $flowContainerId = $flow->container_id;

        return is_string($flowContainerId) && $flowContainerId !== ''
            ? $flowContainerId
            : null;
    }

    /**
     * @return list<array{slug: string, source_line: int|null}>
     */
    public function declaredWebhooks(?string $code): array
    {
        if (! is_string($code) || trim($code) === '') {
            return [];
        }

        $cacheKey = hash('sha256', $code);

        if (isset($this->codeWebhookCache[$cacheKey])) {
            return $this->codeWebhookCache[$cacheKey];
        }

        $process = new Process(['python3', '-c', $this->webhookAstParserScript()]);
        $process->setInput($code);
        $process->setTimeout(2.0);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::debug('flow webhook AST parsing failed', [
                'error' => trim($process->getErrorOutput()),
            ]);

            return $this->codeWebhookCache[$cacheKey] = [];
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded)) {
            return $this->codeWebhookCache[$cacheKey] = [];
        }

        $declarationsBySlug = [];

        foreach ($decoded as $declaration) {
            if (! is_array($declaration)) {
                continue;
            }

            $slug = trim((string) ($declaration['slug'] ?? ''));
            if ($slug === '' || isset($declarationsBySlug[$slug])) {
                continue;
            }

            $sourceLine = $declaration['source_line'] ?? null;

            $declarationsBySlug[$slug] = [
                'slug' => $slug,
                'source_line' => is_int($sourceLine) && $sourceLine > 0 ? $sourceLine : null,
            ];
        }

        return $this->codeWebhookCache[$cacheKey] = array_values($declarationsBySlug);
    }

    private function runHasWebhook(Flow $flow, FlowRun $run, string $slug): bool
    {
        $normalizedSlug = trim($slug);

        if ($normalizedSlug === '') {
            return false;
        }

        foreach ($this->runWebhooks($flow, $run) as $declaration) {
            if ($declaration['slug'] === $normalizedSlug) {
                return true;
            }
        }

        return false;
    }

    private function runCode(Flow $flow, FlowRun $run): string
    {
        return is_string($run->code_snapshot) ? $run->code_snapshot : (string) ($flow->code ?? '');
    }

    /**
     * @return list<array{slug: string, source_line: int|null}>
     */
    private function runWebhooks(Flow $flow, FlowRun $run, ?string $code = null): array
    {
        $graphDeclarations = $this->graphWebhooks($run);

        if ($graphDeclarations !== []) {
            return $graphDeclarations;
        }

        return $this->declaredWebhooks($code ?? $this->runCode($flow, $run));
    }

    /**
     * @return list<array{slug: string, source_line: int|null}>
     */
    private function graphWebhooks(FlowRun $run): array
    {
        $graphSnapshot = $run->graph_snapshot;
        $graphEvents = is_array($graphSnapshot) && is_array($graphSnapshot['events'] ?? null)
            ? $graphSnapshot['events']
            : (is_array($run->events) ? $run->events : []);

        if ($graphEvents === []) {
            return [];
        }

        $declarationsBySlug = [];

        foreach ($graphEvents as $event) {
            $eventName = null;
            $sourceLine = null;

            if (is_string($event)) {
                $eventName = $event;
            }

            if (is_array($event)) {
                $eventName = is_string($event['id'] ?? null)
                    ? $event['id']
                    : (is_string($event['name'] ?? null) ? $event['name'] : null);
                $sourceLine = is_int($event['source_line'] ?? null) ? $event['source_line'] : null;
            }

            $slug = $this->extractWebhookSlug($eventName);

            if ($slug === null) {
                continue;
            }

            if (isset($declarationsBySlug[$slug])) {
                continue;
            }

            $declarationsBySlug[$slug] = [
                'slug' => $slug,
                'source_line' => $sourceLine,
            ];
        }

        return array_values($declarationsBySlug);
    }

    private function extractWebhookSlug(string $eventName): ?string
    {
        if (! preg_match('/^Webhook(?:Event)?\.by\(\s*(.+?)\s*\)$/', trim($eventName), $matches)) {
            return null;
        }

        $rawSlug = trim((string) ($matches[1] ?? ''));

        if ($rawSlug === '') {
            return null;
        }

        if (
            (str_starts_with($rawSlug, '"') && str_ends_with($rawSlug, '"'))
            || (str_starts_with($rawSlug, "'") && str_ends_with($rawSlug, "'"))
        ) {
            $rawSlug = trim(substr($rawSlug, 1, -1));
        }

        return $rawSlug !== '' ? $rawSlug : null;
    }

    private function webhookAstParserScript(): string
    {
        return <<<'PY'
import ast
import json
import sys


SOURCE = sys.stdin.read()


def resolve_name(node):
    if isinstance(node, ast.Name):
        return node.id

    if isinstance(node, ast.Attribute):
        base = resolve_name(node.value)
        if base:
            return f"{base}.{node.attr}"
        return node.attr

    return None


def actor_decorator(decorator):
    if isinstance(decorator, ast.Name):
        return decorator.id == 'actor'

    if isinstance(decorator, ast.Call):
        return resolve_name(decorator.func) == 'actor'

    return False


def is_webhook_base(name):
    if not isinstance(name, str):
        return False

    if name in webhook_aliases:
        return True

    return any(name == f"{alias}.Webhook" for alias in module_aliases)


def iter_webhook_calls(node):
    if isinstance(node, (ast.Tuple, ast.List, ast.Set)):
        for item in node.elts:
            yield from iter_webhook_calls(item)
        return

    if isinstance(node, ast.Call):
        func_name = resolve_name(node.func)
        if func_name and func_name.endswith('.by'):
            base_name = func_name[:-3]
            if is_webhook_base(base_name) and node.args:
                first_arg = node.args[0]
                if isinstance(first_arg, ast.Constant) and isinstance(first_arg.value, str):
                    yield {
                        'slug': first_arg.value,
                        'source_line': getattr(node, 'lineno', None),
                    }

        for child in ast.iter_child_nodes(node):
            yield from iter_webhook_calls(child)


try:
    tree = ast.parse(SOURCE)
except SyntaxError:
    print('[]')
    raise SystemExit(0)

webhook_aliases = {'Webhook'}
module_aliases = {'kawa', 'kawa.webhook'}

for node in tree.body:
    if isinstance(node, ast.ImportFrom) and node.module in {'kawa', 'kawa.webhook'}:
        for alias in node.names:
            if alias.name == 'Webhook':
                webhook_aliases.add(alias.asname or alias.name)

    if isinstance(node, ast.Import):
        for alias in node.names:
            if alias.name in {'kawa', 'kawa.webhook'}:
                module_aliases.add(alias.asname or alias.name)

declarations = []
seen = set()

for node in ast.walk(tree):
    if not isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
        continue

    for decorator in node.decorator_list:
        if not actor_decorator(decorator) or not isinstance(decorator, ast.Call):
            continue

        for keyword in decorator.keywords:
            if keyword.arg not in {'receivs', 'receives'}:
                continue

            for declaration in iter_webhook_calls(keyword.value):
                slug = declaration['slug']
                if slug in seen:
                    continue

                seen.add(slug)
                declarations.append(declaration)

print(json.dumps(declarations))
PY;
    }

    /**
     * @param  array<string, array{slug: string, source_line: int|null, production_url: string|null, development_url: string|null}>  $endpointsBySlug
     * @param  list<array{slug: string, source_line: int|null, production_url: string|null, development_url: string|null}>  $endpoints
     */
    private function mergeEndpoints(array &$endpointsBySlug, array $endpoints): void
    {
        foreach ($endpoints as $endpoint) {
            $existing = $endpointsBySlug[$endpoint['slug']] ?? null;

            if ($existing === null) {
                $endpointsBySlug[$endpoint['slug']] = $endpoint;

                continue;
            }

            $endpointsBySlug[$endpoint['slug']] = [
                'slug' => $endpoint['slug'],
                'source_line' => $existing['source_line'] ?? $endpoint['source_line'],
                'production_url' => $existing['production_url'] ?? $endpoint['production_url'],
                'development_url' => $existing['development_url'] ?? $endpoint['development_url'],
            ];
        }
    }

    private function developmentUrlExpiresAt(): DateTimeInterface
    {
        return $this->developmentUrlExpiresAt ??= now()->addMinutes(self::DEVELOPMENT_URL_TTL_MINUTES);
    }
}
