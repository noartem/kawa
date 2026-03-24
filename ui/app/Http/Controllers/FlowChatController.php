<?php

namespace App\Http\Controllers;

use App\Http\Requests\Flows\FlowChatRequest;
use App\Http\Requests\Flows\FlowChatsIndexRequest;
use App\Models\Flow;
use App\Services\FlowChatService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class FlowChatController extends Controller
{
    private const CHATS_PER_PAGE = 15;

    public function index(
        FlowChatsIndexRequest $request,
        Flow $flow,
        FlowChatService $flowChatService,
    ): Response {
        $filters = [
            'search' => $request->search(),
        ];

        $sorting = [
            'column' => $request->sortBy(),
            'direction' => $request->sortDirection(),
        ];

        return Inertia::render('flows/Chats', [
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
            ],
            'filters' => $filters,
            'sorting' => $sorting,
            'chats' => $flowChatService->paginatedArchivedChats(
                $flow,
                self::CHATS_PER_PAGE,
                $filters,
                $sorting,
            ),
        ]);
    }

    public function debug(
        Request $request,
        Flow $flow,
        FlowChatService $flowChatService,
    ): Response {
        abort_unless(app()->isLocal() || app()->runningUnitTests(), 404);

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:10000'],
            'current_code' => ['nullable', 'string'],
            'should_generate_title' => ['nullable', 'boolean'],
        ]);

        $message = trim((string) ($data['message'] ?? 'Describe what this flow does and suggest the next safe improvement.'));
        $currentCode = array_key_exists('current_code', $data)
            ? (string) $data['current_code']
            : (string) ($flow->code ?? '');
        $shouldGenerateTitle = array_key_exists('should_generate_title', $data)
            ? (bool) $data['should_generate_title']
            : ! $flow->activeChatConversation || $flow->activeChatConversation->messages()->count() === 0;

        return Inertia::render('flows/ChatDebug', [
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
            ],
            'debugUrl' => route('flows.chat.debug', $flow),
            'preview' => $flowChatService->debugPayload(
                $flow,
                $message,
                $currentCode,
                $shouldGenerateTitle,
            ),
        ]);
    }

    public function store(
        FlowChatRequest $request,
        Flow $flow,
        FlowChatService $flowChatService,
    ): JsonResponse {
        return $this->handleChatAction(fn (): array => $flowChatService->sendMessage(
            $flow,
            $request->user(),
            $request->message(),
            $request->currentCode(),
        ));
    }

    public function newChat(
        Flow $flow,
        FlowChatService $flowChatService,
    ): JsonResponse {
        return $this->handleChatAction(fn (): array => $flowChatService->startNewChat($flow));
    }

    public function compact(
        Request $request,
        Flow $flow,
        FlowChatService $flowChatService,
    ): JsonResponse {
        $data = $request->validate([
            'current_code' => ['present', 'nullable', 'string'],
        ]);

        return $this->handleChatAction(fn (): array => $flowChatService->compactActiveChat(
            $flow,
            $request->user(),
            (string) ($data['current_code'] ?? ''),
        ));
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    private function handleChatAction(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RateLimitedException $exception) {
            report($exception);

            return $this->errorResponse(
                message: 'The AI provider is rate limiting requests right now. Please try again in a moment.',
                code: 'ai_rate_limited',
                status: 429,
            );
        } catch (InsufficientCreditsException $exception) {
            report($exception);

            return $this->errorResponse(
                message: 'The AI provider has no available quota right now. Please try again later.',
                code: 'ai_insufficient_credits',
                status: 503,
            );
        } catch (ProviderOverloadedException|ConnectionException $exception) {
            report($exception);

            return $this->errorResponse(
                message: 'The AI provider is temporarily unavailable. Please try again in a minute.',
                code: 'ai_provider_unavailable',
                status: 503,
            );
        } catch (AiException $exception) {
            report($exception);

            return $this->errorResponse(
                message: $this->resolveAiExceptionMessage($exception),
                code: $this->resolveAiExceptionCode($exception),
                status: $this->resolveAiExceptionStatus($exception),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->errorResponse(
                message: 'The chat request failed unexpectedly. Please try again.',
                code: 'chat_request_failed',
                status: 500,
            );
        }
    }

    private function errorResponse(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
        ], $status);
    }

    private function resolveAiExceptionMessage(AiException $exception): string
    {
        return match ($this->resolveAiExceptionCode($exception)) {
            'ai_provider_unavailable' => 'The AI provider is temporarily unavailable. Please try again in a minute.',
            'ai_rate_limited' => 'The AI provider is rate limiting requests right now. Please try again in a moment.',
            'ai_insufficient_credits' => 'The AI provider has no available quota right now. Please try again later.',
            default => 'The AI request failed. Please try again.',
        };
    }

    private function resolveAiExceptionCode(AiException $exception): string
    {
        if ($exception->getCode() === 429) {
            return 'ai_rate_limited';
        }

        if ($exception->getCode() === 503 || str_contains($exception->getMessage(), 'Unknown error')) {
            return 'ai_provider_unavailable';
        }

        if (str_contains(strtolower($exception->getMessage()), 'quota') || str_contains(strtolower($exception->getMessage()), 'credit')) {
            return 'ai_insufficient_credits';
        }

        return 'ai_request_failed';
    }

    private function resolveAiExceptionStatus(AiException $exception): int
    {
        return match ($this->resolveAiExceptionCode($exception)) {
            'ai_rate_limited' => 429,
            'ai_provider_unavailable', 'ai_insufficient_credits' => 503,
            default => 502,
        };
    }
}
