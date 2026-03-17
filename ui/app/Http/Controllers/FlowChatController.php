<?php

namespace App\Http\Controllers;

use App\Http\Requests\Flows\FlowChatRequest;
use App\Models\Flow;
use App\Services\FlowChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowChatController extends Controller
{
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
