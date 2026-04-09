<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Services\FlowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FlowActionController extends Controller
{
    private const DEFAULT_EDITOR_DEPLOYMENT = 'development';

    private const DEFAULT_EDITOR_TAB = 'overview';

    private const EDITOR_DEPLOYMENTS = [
        'development',
        'production',
    ];

    private const EDITOR_TABS = [
        'overview',
        'editor',
        'chat',
        'storage',
        'discovery',
        'changes',
    ];

    public function run(Request $request, Flow $flow, FlowService $flows): RedirectResponse
    {
        $result = $flows->start($flow);

        return redirect()
            ->route('flows.show', $this->editorRouteParameters($request, $flow))
            ->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? __('flows.run.success')
                    : ($result['message'] ?? __('flows.run.error'))
            );
    }

    public function stop(Request $request, Flow $flow, FlowService $flows): RedirectResponse
    {
        $result = $flows->stop($flow);

        return redirect()
            ->route('flows.show', $this->editorRouteParameters($request, $flow))
            ->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? __('flows.stop.success')
                    : ($result['message'] ?? __('flows.stop.error'))
            );
    }

    public function deploy(Request $request, Flow $flow, FlowService $flows): RedirectResponse
    {
        $result = $flows->deployProduction($flow);

        return redirect()
            ->route('flows.show', $this->editorRouteParameters($request, $flow))
            ->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? __('flows.deploy.success')
                    : ($result['message'] ?? __('flows.deploy.error'))
            );
    }

    public function undeploy(Request $request, Flow $flow, FlowService $flows): RedirectResponse
    {
        $result = $flows->undeployProduction($flow);

        return redirect()
            ->route('flows.show', $this->editorRouteParameters($request, $flow))
            ->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? __('flows.undeploy.success')
                    : ($result['message'] ?? __('flows.undeploy.error'))
            );
    }

    public function archive(Request $request, Flow $flow): RedirectResponse
    {
        if ($flow->hasActiveDeploys()) {
            return redirect()
                ->route('flows.show', $this->editorRouteParameters($request, $flow))
                ->with('error', __('flows.archive.error_active'));
        }

        $flow->update([
            'archived_at' => now(),
        ]);

        return redirect()
            ->route('flows.show', $this->editorRouteParameters($request, $flow))
            ->with('success', __('flows.archived'));
    }

    public function restore(Request $request, Flow $flow): RedirectResponse
    {
        $flow->update([
            'archived_at' => null,
        ]);

        return redirect()
            ->route('flows.show', $this->editorRouteParameters($request, $flow))
            ->with('success', __('flows.restored'));
    }

    /**
     * @return array{flow: Flow, deployment: string, tab: string}
     */
    private function editorRouteParameters(Request $request, Flow $flow): array
    {
        return [
            'flow' => $flow,
            'deployment' => $this->resolveEditorDeploymentType($request),
            'tab' => $this->resolveEditorTab($request),
        ];
    }

    private function resolveEditorDeploymentType(Request $request): string
    {
        $deployment = $request->query('deployment');

        return is_string($deployment) && in_array($deployment, self::EDITOR_DEPLOYMENTS, true)
            ? $deployment
            : self::DEFAULT_EDITOR_DEPLOYMENT;
    }

    private function resolveEditorTab(Request $request): string
    {
        $tab = $request->query('tab');

        return is_string($tab) && in_array($tab, self::EDITOR_TABS, true)
            ? $tab
            : self::DEFAULT_EDITOR_TAB;
    }
}
