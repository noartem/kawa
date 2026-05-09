<?php

namespace App\Services;

use App\Ai\Agents\FlowChatCompactor;
use App\Ai\Agents\FlowCodeAssistant;
use App\Jobs\ProcessFlowChatRequest;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\Flow;
use App\Models\FlowChatRequestStatus;
use App\Models\User;
use App\Support\FlowCodeDiff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Messages\Message;
use Throwable;

class FlowChatService
{
    private const DEFAULT_MAX_HISTORY_MESSAGES = 12;

    public function __construct(
        private readonly FlowCodeDiff $flowCodeDiff,
        private readonly FlowChatCompletionClient $flowChatCompletionClient,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function activeChatPayload(Flow $flow): ?array
    {
        $conversation = $flow->activeChatConversation;

        if (! $conversation instanceof AgentConversation) {
            return null;
        }

        $conversation->loadMissing('messages');

        return $this->serializeConversation($conversation);
    }

    /**
     * @return array<string, mixed>
     */
    public function conversationPayload(AgentConversation $conversation): array
    {
        $conversation->loadMissing('messages');

        return $this->serializeConversation($conversation);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pastChatsPayload(Flow $flow): array
    {
        return $flow->conversations()
            ->when(
                $flow->active_chat_conversation_id,
                fn ($query, $activeChatId) => $query->where('id', '!=', $activeChatId),
            )
            ->with('messages')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (AgentConversation $conversation) => $this->serializeConversation($conversation))
            ->values()
            ->all();
    }

    /**
     * @param  array{search: ?string}  $filters
     * @param  array{column: string, direction: string}  $sorting
     */
    public function paginatedArchivedChats(
        Flow $flow,
        int $perPage,
        array $filters,
        array $sorting,
    ): LengthAwarePaginator {
        $search = $filters['search'];
        $sortColumn = $sorting['column'];
        $sortDirection = $sorting['direction'];

        $conversations = $flow->conversations()
            ->when(
                $flow->active_chat_conversation_id,
                fn ($query, $activeChatId) => $query->where('id', '!=', $activeChatId),
            )
            ->when($search !== null, function ($query) use ($search): void {
                $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

                $query->where(function ($whereQuery) use ($escapedSearch): void {
                    $whereQuery
                        ->where('id', 'like', '%'.$escapedSearch.'%')
                        ->orWhere('title', 'like', '%'.$escapedSearch.'%')
                        ->orWhereHas('messages', function ($messageQuery) use ($escapedSearch): void {
                            $messageQuery->where('content', 'like', '%'.$escapedSearch.'%');
                        });
                });
            })
            ->withCount('messages')
            ->with('messages')
            ->orderBy($sortColumn, $sortDirection)
            ->when($sortColumn !== 'id', function ($query) use ($sortDirection): void {
                $query->orderBy('id', $sortDirection);
            })
            ->paginate($perPage)
            ->withQueryString();

        $conversations->setCollection(
            $conversations->getCollection()->map(
                fn (AgentConversation $conversation): array => $this->serializeConversation($conversation),
            ),
        );

        return $conversations;
    }

    /**
     * @return array<string, mixed>
     */
    public function submitMessage(
        Flow $flow,
        AgentConversation $conversation,
        User $user,
        string $message,
        string $currentCode,
    ): array {
        if (FlowCodeAssistant::isFaked()) {
            return [
                ...$this->sendMessage($flow, $conversation, $user, $message, $currentCode),
                'chatRequest' => null,
                'status' => FlowChatRequestStatus::STATUS_COMPLETED,
            ];
        }

        $message = $this->sanitizeUtf8($message);
        $currentCode = $this->sanitizeUtf8($currentCode);

        $chatRequest = DB::transaction(function () use ($conversation, $currentCode, $flow, $message, $user): FlowChatRequestStatus {
            $chatRequest = $conversation->chatRequests()->create([
                'flow_id' => $flow->id,
                'user_id' => $user->id,
                'status' => FlowChatRequestStatus::STATUS_PENDING,
                'message' => $message,
                'current_code' => $currentCode,
            ]);

            ProcessFlowChatRequest::dispatch($chatRequest->id)->afterCommit();

            return $chatRequest;
        });

        return [
            'activeChat' => $this->serializeConversation($conversation->fresh('messages')),
            'pastChats' => $this->pastChatsPayload($flow->fresh()),
            'chatRequest' => $this->serializeChatRequest($flow, $conversation, $chatRequest),
            'status' => $chatRequest->status,
            'error' => null,
            '__http_status' => 202,
        ];
    }

    /**
     * @return array{activeChat: array<string, mixed>, pastChats: array<int, array<string, mixed>>}
     */
    public function sendMessage(
        Flow $flow,
        AgentConversation $conversation,
        User $user,
        string $message,
        string $currentCode,
    ): array {
        $message = $this->sanitizeUtf8($message);
        $currentCode = $this->sanitizeUtf8($currentCode);

        $conversation->loadMissing('messages');
        $shouldGenerateTitle = $conversation->messages->isEmpty();

        $existingMessageCount = $conversation->messages->count();
        $assistant = $this->makeFlowCodeAssistant(
            conversation: $conversation,
            user: $user,
            currentCode: $currentCode,
            shouldGenerateTitle: $shouldGenerateTitle,
        );
        $response = $this->flowChatCompletionClient->complete($assistant, $message);

        $assistantTitle = trim($this->sanitizeUtf8((string) ($response['title'] ?? '')));
        $assistantReply = trim($this->sanitizeUtf8((string) ($response['reply'] ?? '')));
        $assistantCode = $this->sanitizeUtf8((string) ($response['code'] ?? $currentCode));

        if (Str::of($assistantCode)->trim()->isEmpty()) {
            $assistantCode = $currentCode;
        }

        if (! $this->hasMeaningfulCodeChanges($currentCode, $assistantCode)) {
            $assistantCode = $currentCode;
        }

        $assistantResponseMode = (string) ($response['response_mode'] ?? '');
        $normalizedResponseMode = $assistantResponseMode === FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY
            || $assistantResponseMode === FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE
            ? $assistantResponseMode
            : ($assistantCode !== $currentCode
                ? FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE
                : FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY);

        $hasCodeChanges = $normalizedResponseMode === FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE
            && $assistantCode !== $currentCode;
        $assistantDiff = $hasCodeChanges
            ? $this->sanitizeUtf8($this->flowCodeDiff->build($currentCode, $assistantCode))
            : null;

        $conversation = $this->storeExchange(
            conversationId: (string) ($response['conversation_id'] ?? $conversation->id),
            user: $user,
            existingMessageCount: $existingMessageCount,
            userMessage: $message,
            assistantReply: $assistantReply,
            assistantMeta: [
                'kind' => $hasCodeChanges ? 'code_suggestion' : 'assistant_reply',
                'response_mode' => $normalizedResponseMode,
                'source_code' => $currentCode,
                'proposed_code' => $assistantCode,
                'diff' => $assistantDiff,
                'provider' => is_string($response['provider'] ?? null)
                    ? $response['provider']
                    : 'openai',
                'model' => is_string($response['model'] ?? null)
                    ? $response['model']
                    : FlowCodeAssistant::MODEL,
            ],
            assistantUsage: is_array($response['usage'] ?? null) ? $response['usage'] : [],
            messagesPersistedByAgent: (bool) ($response['persisted_by_agent'] ?? false),
        );

        if ($shouldGenerateTitle) {
            $conversation->title = $assistantTitle !== ''
                ? Str::limit($assistantTitle, 100, preserveWords: true)
                : Str::limit($message, 100, preserveWords: true);
        }

        $conversation->forceFill(['updated_at' => now()])->save();
        $flow->forceFill(['active_chat_conversation_id' => $conversation->id])->save();

        return [
            'activeChat' => $this->serializeConversation($conversation->fresh('messages')),
            'pastChats' => $this->pastChatsPayload($flow->fresh()),
        ];
    }

    public function processQueuedMessage(int $chatRequestId): void
    {
        /** @var FlowChatRequestStatus $chatRequest */
        $chatRequest = FlowChatRequestStatus::query()
            ->with(['flow', 'conversation', 'user'])
            ->findOrFail($chatRequestId);

        if (in_array($chatRequest->status, [
            FlowChatRequestStatus::STATUS_COMPLETED,
            FlowChatRequestStatus::STATUS_FAILED,
        ], true)) {
            return;
        }

        $chatRequest->forceFill([
            'status' => FlowChatRequestStatus::STATUS_PROCESSING,
            'error_code' => null,
            'error_message' => null,
            'error_status' => null,
            'completed_at' => null,
        ])->save();

        try {
            $this->sendMessage(
                $chatRequest->flow()->firstOrFail(),
                $chatRequest->conversation()->firstOrFail(),
                $chatRequest->user()->firstOrFail(),
                $chatRequest->message,
                (string) ($chatRequest->current_code ?? ''),
            );

            $chatRequest->forceFill([
                'status' => FlowChatRequestStatus::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);

            $error = $this->resolveAsyncChatError($exception);

            $chatRequest->forceFill([
                'status' => FlowChatRequestStatus::STATUS_FAILED,
                'error_code' => $error['code'],
                'error_message' => $error['message'],
                'error_status' => $error['status'],
                'completed_at' => now(),
            ])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function chatRequestPayload(
        Flow $flow,
        AgentConversation $conversation,
        FlowChatRequestStatus $chatRequest,
    ): array {
        $chatRequest->loadMissing('conversation.messages');

        return [
            'activeChat' => $this->serializeConversation($conversation->fresh('messages')),
            'pastChats' => $this->pastChatsPayload($flow->fresh()),
            'chatRequest' => $this->serializeChatRequest($flow, $conversation, $chatRequest),
            'status' => $chatRequest->status,
            'error' => $chatRequest->status === FlowChatRequestStatus::STATUS_FAILED
                ? [
                    'message' => $chatRequest->error_message,
                    'code' => $chatRequest->error_code,
                    'status' => $chatRequest->error_status,
                ]
                : null,
        ];
    }

    /**
     * @return array{activeChat: array<string, mixed>, pastChats: array<int, array<string, mixed>>}
     */
    public function createChat(Flow $flow, User $user): array
    {
        $conversation = $flow->conversations()->create([
            'user_id' => $user->id,
            'title' => 'New chat',
        ]);

        $flow->forceFill(['active_chat_conversation_id' => $conversation->id])->save();

        return [
            'activeChat' => $this->serializeConversation($conversation->fresh('messages')),
            'pastChats' => $this->pastChatsPayload($flow->fresh()),
        ];
    }

    /**
     * @return array{activeChat: array<string, mixed>, pastChats: array<int, array<string, mixed>>}
     */
    public function compactChat(
        Flow $flow,
        AgentConversation $conversation,
        User $user,
        string $currentCode,
    ): array {
        $conversation->loadMissing('messages');

        if ($conversation->messages->isEmpty()) {
            throw ValidationException::withMessages([
                'chat' => 'No active chat to compact.',
            ]);
        }

        $response = FlowChatCompactor::make(
            currentCode: $currentCode,
            messages: $this->conversationMessagesForAgent($conversation, $currentCode),
        )->prompt('Compress this chat into a fresh continuation summary.');

        $title = trim($this->sanitizeUtf8((string) ($response['title'] ?? '')));
        $summary = trim($this->sanitizeUtf8((string) ($response['summary'] ?? '')));

        $newConversation = $flow->conversations()->create([
            'user_id' => $user->id,
            'title' => $title !== '' ? Str::limit($title, 100) : 'Compacted chat',
        ]);

        $newConversation->messages()->create([
            'user_id' => $user->id,
            'agent' => FlowChatCompactor::class,
            'role' => 'assistant',
            'content' => $summary,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [
                'kind' => 'compact_summary',
                'source_conversation_id' => $conversation->id,
            ],
        ]);

        $newConversation->forceFill(['updated_at' => now()])->save();
        $flow->forceFill(['active_chat_conversation_id' => $newConversation->id])->save();

        return [
            'activeChat' => $this->serializeConversation($newConversation->fresh('messages')),
            'pastChats' => $this->pastChatsPayload($flow->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function debugPayload(
        Flow $flow,
        string $message,
        string $currentCode,
        bool $shouldGenerateTitle,
    ): array {
        $flow->loadMissing('activeChatConversation.messages');

        $activeConversation = $flow->activeChatConversation;
        $history = $activeConversation instanceof AgentConversation
            ? $activeConversation->messages
            : collect();

        $assistant = new FlowCodeAssistant(
            currentCode: $currentCode,
            messages: $activeConversation instanceof AgentConversation
                ? $this->conversationMessagesForAgent($activeConversation, $currentCode)
                : [],
            shouldGenerateTitle: $shouldGenerateTitle,
        );

        $historyPayload = $history
            ->map(function (AgentConversationMessage $historyMessage): array {
                $meta = is_array($historyMessage->meta) ? $historyMessage->meta : [];

                return [
                    'id' => $historyMessage->id,
                    'role' => $historyMessage->role,
                    'agent' => $historyMessage->agent,
                    'content' => $historyMessage->content,
                    'kind' => is_string($meta['kind'] ?? null) ? $meta['kind'] : null,
                    'response_mode' => is_string($meta['response_mode'] ?? null)
                        ? $meta['response_mode']
                        : null,
                    'created_at' => optional($historyMessage->created_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'provider' => (string) config('ai.default'),
            'model' => FlowCodeAssistant::MODEL,
            'base_url' => config('ai.providers.openai.url'),
            'should_generate_title' => $shouldGenerateTitle,
            'user_message' => $message,
            'current_code' => $currentCode,
            'instructions' => (string) $assistant->instructions(),
            'schema' => FlowCodeAssistant::schemaPreview($shouldGenerateTitle),
            'active_conversation' => $activeConversation instanceof AgentConversation
                ? [
                    'id' => $activeConversation->id,
                    'title' => $activeConversation->title,
                    'memory_strategy' => 'continue',
                ]
                : null,
            'history' => $historyPayload,
            'history_strategy' => 'role_preserving_messages',
            'request_preview' => [
                'system_prompt' => (string) $assistant->instructions(),
                'history_messages' => array_map(
                    static fn (Message $historyMessage): array => [
                        'role' => $historyMessage->role->value,
                        'content' => $historyMessage->content,
                    ],
                    $activeConversation instanceof AgentConversation
                        ? $this->conversationMessagesForAgent($activeConversation, $currentCode)
                        : [],
                ),
                'user_message' => $message,
                'should_generate_title' => $shouldGenerateTitle,
                'structured_output' => FlowCodeAssistant::schemaPreview($shouldGenerateTitle),
            ],
        ];
    }

    private function makeFlowCodeAssistant(
        AgentConversation $conversation,
        User $user,
        string $currentCode,
        bool $shouldGenerateTitle,
    ): FlowCodeAssistant {
        return FlowCodeAssistant::make(
            currentCode: $currentCode,
            messages: $this->conversationMessagesForAgent($conversation, $currentCode),
            shouldGenerateTitle: $shouldGenerateTitle,
        )->continue($conversation->id, as: $user);
    }

    /**
     * @param  array<string, mixed>  $assistantMeta
     */
    private function storeExchange(
        string $conversationId,
        User $user,
        int $existingMessageCount,
        string $userMessage,
        string $assistantReply,
        array $assistantMeta,
        array $assistantUsage,
        bool $messagesPersistedByAgent,
    ): AgentConversation {
        return DB::transaction(function () use (
            $assistantMeta,
            $assistantReply,
            $assistantUsage,
            $conversationId,
            $existingMessageCount,
            $messagesPersistedByAgent,
            $user,
            $userMessage,
        ): AgentConversation {
            $conversation = AgentConversation::query()
                ->with('messages')
                ->findOrFail($conversationId);

            if (! $messagesPersistedByAgent) {
                $conversation->messages()->create([
                    'user_id' => $user->id,
                    'agent' => FlowCodeAssistant::class,
                    'role' => 'user',
                    'content' => $userMessage,
                    'attachments' => [],
                    'tool_calls' => [],
                    'tool_results' => [],
                    'usage' => [],
                    'meta' => ['kind' => 'prompt'],
                ]);

                $conversation->messages()->create([
                    'user_id' => $user->id,
                    'agent' => FlowCodeAssistant::class,
                    'role' => 'assistant',
                    'content' => $assistantReply,
                    'attachments' => [],
                    'tool_calls' => [],
                    'tool_results' => [],
                    'usage' => $this->sanitizeForJson($assistantUsage),
                    'meta' => $this->sanitizeForJson($assistantMeta),
                ]);

                return $conversation->fresh('messages');
            }

            /** @var EloquentCollection<int, AgentConversationMessage> $newMessages */
            $newMessages = $conversation->messages
                ->slice($existingMessageCount)
                ->values();

            /** @var AgentConversationMessage|null $latestUserMessage */
            $latestUserMessage = $newMessages->firstWhere('role', 'user');
            /** @var AgentConversationMessage|null $latestAssistantMessage */
            $latestAssistantMessage = $newMessages
                ->reverse()
                ->firstWhere('role', 'assistant');

            if (! $latestUserMessage instanceof AgentConversationMessage
                || ! $latestAssistantMessage instanceof AgentConversationMessage) {
                throw ValidationException::withMessages([
                    'chat' => 'The chat history could not be saved. Please try again.',
                ]);
            }

            $latestUserMessage->forceFill([
                'meta' => $this->sanitizeForJson(array_merge(
                    is_array($latestUserMessage->meta) ? $latestUserMessage->meta : [],
                    ['kind' => 'prompt'],
                )),
            ])->save();

            $latestAssistantMessage->forceFill([
                'content' => $assistantReply,
                'meta' => $this->sanitizeForJson(array_merge(
                    is_array($latestAssistantMessage->meta) ? $latestAssistantMessage->meta : [],
                    $assistantMeta,
                )),
            ])->save();

            return $conversation->fresh('messages');
        });
    }

    /**
     * @return array<int, Message>
     */
    private function conversationMessagesForAgent(
        AgentConversation $conversation,
        string $currentCode,
    ): array {
        $conversation->loadMissing('messages');

        if ($conversation->messages->isEmpty()) {
            return [];
        }

        $maxHistoryMessages = (int) config('ai.chat.max_history_messages', self::DEFAULT_MAX_HISTORY_MESSAGES);
        $messages = $conversation->messages;

        if ($maxHistoryMessages > 0) {
            $messages = $messages->slice(-$maxHistoryMessages)->values();
        }

        return $messages
            ->map(fn (AgentConversationMessage $message): Message => new Message(
                $message->role,
                $this->buildConversationMessageContent($message, $currentCode),
            ))
            ->filter(fn (Message $message): bool => trim($message->content) !== '')
            ->values()
            ->all();
    }

    private function buildConversationMessageContent(
        AgentConversationMessage $message,
        string $currentCode,
    ): string {
        $lines = [];
        $content = trim($message->content);

        if ($content !== '') {
            $lines[] = $content;
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        $responseMode = is_string($meta['response_mode'] ?? null)
            ? $meta['response_mode']
            : null;
        $proposedCode = is_string($meta['proposed_code'] ?? null)
            ? $meta['proposed_code']
            : null;
        $kind = is_string($meta['kind'] ?? null) ? $meta['kind'] : null;

        if ($message->role === 'assistant' && $responseMode !== null) {
            $lines[] = sprintf('Response mode: %s', $responseMode);
        }

        if ($message->role === 'assistant' && $this->messageContainsCodeProposal($kind, $proposedCode, $responseMode)) {
            $lines[] = $this->assistantProposalMatchesCurrentCode($proposedCode, $currentCode)
                ? 'The current code matches this previously suggested change.'
                : 'This reply proposed a code change. The proposal was not applied automatically.';
            $lines[] = 'Use the current code as the source of truth.';
        }

        return implode("\n", $lines);
    }

    private function messageContainsCodeProposal(
        ?string $kind,
        ?string $proposedCode,
        ?string $responseMode,
    ): bool {
        if ($proposedCode === null) {
            return false;
        }

        return $kind === 'code_suggestion'
            || $responseMode === FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE;
    }

    private function assistantProposalMatchesCurrentCode(
        ?string $proposedCode,
        string $currentCode,
    ): bool {
        if ($proposedCode === null) {
            return false;
        }

        return ! $this->hasMeaningfulCodeChanges($proposedCode, $currentCode);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversation(AgentConversation $conversation): array
    {
        $messages = $conversation->messages instanceof Collection
            ? $conversation->messages
            : collect();

        /** @var AgentConversationMessage|null $lastMessage */
        $lastMessage = $messages->last();

        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'created_at' => optional($conversation->created_at)?->toIso8601String(),
            'updated_at' => optional($conversation->updated_at)?->toIso8601String(),
            'preview' => Str::limit((string) $lastMessage?->content, 140, preserveWords: true),
            'messages_count' => $messages->count(),
            'messages' => $messages
                ->map(fn (AgentConversationMessage $message) => $this->serializeMessage($message))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(AgentConversationMessage $message): array
    {
        $meta = is_array($message->meta) ? $message->meta : [];
        $proposedCode = is_string($meta['proposed_code'] ?? null)
            ? $meta['proposed_code']
            : null;
        $sourceCode = is_string($meta['source_code'] ?? null)
            ? $meta['source_code']
            : null;
        $diff = is_string($meta['diff'] ?? null) ? $meta['diff'] : null;

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'kind' => is_string($meta['kind'] ?? null) ? $meta['kind'] : null,
            'response_mode' => is_string($meta['response_mode'] ?? null)
                ? $meta['response_mode']
                : null,
            'source_code' => $sourceCode,
            'proposed_code' => $proposedCode,
            'diff' => $diff,
            'has_code_changes' => $proposedCode !== null
                && $sourceCode !== null
                && $this->hasMeaningfulCodeChanges($sourceCode, $proposedCode),
        ];
    }

    private function hasMeaningfulCodeChanges(string $originalCode, string $updatedCode): bool
    {
        return trim($originalCode) !== trim($updatedCode);
    }

    private function sanitizeForJson(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeUtf8($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitizeForJson($item);
            }
        }

        return $value;
    }

    private function sanitizeUtf8(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        $sanitized = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if (is_string($sanitized)) {
            return $sanitized;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeChatRequest(
        Flow $flow,
        AgentConversation $conversation,
        FlowChatRequestStatus $chatRequest,
    ): array {
        return [
            'id' => $chatRequest->id,
            'status' => $chatRequest->status,
            'poll_url' => route('flows.chat.messages.requests.show', [
                'flow' => $flow,
                'chat' => $conversation,
                'chatRequest' => $chatRequest,
            ]),
            'completed_at' => optional($chatRequest->completed_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array{message: string, code: string, status: int}
     */
    private function resolveAsyncChatError(Throwable $exception): array
    {
        if ($exception instanceof RateLimitedException) {
            return [
                'message' => 'The AI provider is rate limiting requests right now. Please try again in a moment.',
                'code' => 'ai_rate_limited',
                'status' => 429,
            ];
        }

        if ($exception instanceof InsufficientCreditsException) {
            return [
                'message' => 'The AI provider has no available quota right now. Please try again later.',
                'code' => 'ai_insufficient_credits',
                'status' => 503,
            ];
        }

        if ($exception instanceof ProviderOverloadedException || $exception instanceof ConnectionException) {
            return [
                'message' => 'The AI provider is temporarily unavailable. Please try again in a minute.',
                'code' => 'ai_provider_unavailable',
                'status' => 503,
            ];
        }

        if ($exception instanceof AiException) {
            $code = $this->resolveAsyncAiExceptionCode($exception);

            return [
                'message' => match ($code) {
                    'ai_provider_unavailable' => 'The AI provider is temporarily unavailable. Please try again in a minute.',
                    'ai_rate_limited' => 'The AI provider is rate limiting requests right now. Please try again in a moment.',
                    'ai_insufficient_credits' => 'The AI provider has no available quota right now. Please try again later.',
                    default => 'The AI request failed. Please try again.',
                },
                'code' => $code,
                'status' => $this->resolveAsyncAiExceptionStatus($exception),
            ];
        }

        return [
            'message' => 'The chat request failed unexpectedly. Please try again.',
            'code' => 'chat_request_failed',
            'status' => 500,
        ];
    }

    private function resolveAsyncAiExceptionCode(AiException $exception): string
    {
        if ($exception->getCode() === 429) {
            return 'ai_rate_limited';
        }

        if ($exception->getCode() === 503 || str_contains($exception->getMessage(), 'Unknown error')) {
            return 'ai_provider_unavailable';
        }

        $message = Str::lower($exception->getMessage());

        if (str_contains($message, 'quota')
            || str_contains($message, 'credit')
            || str_contains($message, 'insufficient')) {
            return 'ai_insufficient_credits';
        }

        return 'ai_request_failed';
    }

    private function resolveAsyncAiExceptionStatus(AiException $exception): int
    {
        return match ($this->resolveAsyncAiExceptionCode($exception)) {
            'ai_rate_limited' => 429,
            'ai_provider_unavailable', 'ai_insufficient_credits' => 503,
            default => 500,
        };
    }
}
