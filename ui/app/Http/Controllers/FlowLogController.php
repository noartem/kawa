<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use Illuminate\Http\JsonResponse;

class FlowLogController extends Controller
{
    public function index(Flow $flow): JsonResponse
    {
        return response()->json([
            'data' => $flow->logs()
                ->latest('created_at')
                ->orderByDesc('id')
                ->paginate(),
        ]);
    }
}
