<?php

namespace App\Jobs;

use App\Services\FlowChatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessFlowChatRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(private readonly int $chatRequestId) {}

    public function handle(FlowChatService $flowChatService): void
    {
        $flowChatService->processQueuedMessage($this->chatRequestId);
    }
}
