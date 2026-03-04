<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Services\FlowService;
use Illuminate\Http\RedirectResponse;

class FlowActionController extends Controller
{
    public function run(Flow $flow, FlowService $flows): RedirectResponse
    {
        $result = $flows->start($flow);

        return redirect()
            ->route('flows.show', $flow)
            ->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? __('flows.run.success')
                    : ($result['message'] ?? __('flows.run.error'))
            );
    }

    public function stop(Flow $flow, FlowService $flows): RedirectResponse
    {
        $result = $flows->stop($flow);

        return redirect()
            ->route('flows.show', $flow)
            ->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? __('flows.stop.success')
                    : ($result['message'] ?? __('flows.stop.error'))
            );
    }

    public function deploy(Flow $flow, FlowService $flows): RedirectResponse
    {
        $result = $flows->deployProduction($flow);

        return redirect()
            ->route('flows.show', $flow)
            ->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? __('flows.deploy.success')
                    : ($result['message'] ?? __('flows.deploy.error'))
            );
    }

    public function undeploy(Flow $flow, FlowService $flows): RedirectResponse
    {
        $result = $flows->undeployProduction($flow);

        return redirect()
            ->route('flows.show', $flow)
            ->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? __('flows.undeploy.success')
                    : ($result['message'] ?? __('flows.undeploy.error'))
            );
    }

    public function archive(Flow $flow): RedirectResponse
    {
        if ($flow->hasActiveDeploys()) {
            return redirect()
                ->route('flows.show', $flow)
                ->with('error', __('flows.archive.error_active'));
        }

        $flow->update([
            'archived_at' => now(),
        ]);

        return redirect()
            ->route('flows.show', $flow)
            ->with('success', __('flows.archived'));
    }

    public function restore(Flow $flow): RedirectResponse
    {
        $flow->update([
            'archived_at' => null,
        ]);

        return redirect()
            ->route('flows.show', $flow)
            ->with('success', __('flows.restored'));
    }
}
