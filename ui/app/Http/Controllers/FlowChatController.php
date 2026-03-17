<?php

namespace App\Http\Controllers;

use App\Http\Requests\Flows\FlowChatRequest;
use App\Models\Flow;
use App\Services\FlowChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FlowChatController extends Controller
{
    public function debug(
        Request $request,
        Flow $flow,
        FlowChatService $flowChatService,
    ): Response {
        abort_unless(app()->isLocal() || app()->runningUnitTests(), 404);

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:10000'],
            'current_code' => ['nullable', 'string'],
        ]);

        $message = trim((string) ($data['message'] ?? 'Describe what this flow does and suggest the next safe improvement.'));
        $currentCode = array_key_exists('current_code', $data)
            ? (string) $data['current_code']
            : (string) ($flow->code ?? '');

        return Inertia::render('flows/ChatDebug', [
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
            ],
            'debugUrl' => route('flows.chat.debug', $flow),
            'preview' => $flowChatService->debugPayload($flow, $message, $currentCode),
        ]);
    }

    public function store(
        FlowChatRequest $request,
        Flow $flow,
        FlowChatService $flowChatService,
    ): JsonResponse {
        return response()->json(
            $flowChatService->sendMessage(
                $flow,
                $request->user(),
                $request->message(),
                $request->currentCode(),
            ),
        );
    }

    public function newChat(
        Flow $flow,
        FlowChatService $flowChatService,
    ): JsonResponse {
        return response()->json($flowChatService->startNewChat($flow));
    }

    public function compact(
        Request $request,
        Flow $flow,
        FlowChatService $flowChatService,
    ): JsonResponse {
        $data = $request->validate([
            'current_code' => ['present', 'string'],
        ]);

        return response()->json(
            $flowChatService->compactActiveChat(
                $flow,
                $request->user(),
                (string) $data['current_code'],
            ),
        );
    }
}
