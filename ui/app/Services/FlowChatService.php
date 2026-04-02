<?php

namespace App\Services;

use App\Ai\Agents\FlowChatCompactor;
use App\Ai\Agents\FlowCodeAssistant;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\Flow;
use App\Models\User;
use App\Support\FlowCodeDiff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Messages\Message;

class FlowChatService
{
    private const SUPPORTED_HISTORY_KINDS = [
        'apply_proposal',
        'apply_and_save_proposal',
    ];

    public function __construct(
        private readonly FlowCodeDiff $flowCodeDiff,
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
     * @return array{activeChat: array<string, mixed>, pastChats: array<int, array<string, mixed>>}
     */
    public function sendMessage(
        Flow $flow,
        User $user,
        string $message,
        string $currentCode,
        array $history = [],
    ): array {
        $message = $this->sanitizeUtf8($message);
        $currentCode = $this->sanitizeUtf8($currentCode);
        $history = $this->sanitizeHistory($history);
        $conversation = $this->resolveActiveConversation($flow, $user, $message);
        $shouldGenerateTitle = $conversation->messages->isEmpty();

        if ($history !== []) {
            $conversation = $this->appendHistoryMessages($conversation, $user, $history);
        }

        $existingMessageCount = $conversation->messages->count();
        $response = $this->makeFlowCodeAssistant(
            conversation: $conversation,
            user: $user,
            currentCode: $currentCode,
            shouldGenerateTitle: $shouldGenerateTitle,
        )->prompt($message);

        $assistantTitle = trim($this->sanitizeUtf8((string) ($response['title'] ?? '')));
        $assistantReply = trim($this->sanitizeUtf8((string) ($response['reply'] ?? '')));
        $assistantCode = $this->sanitizeUtf8((string) ($response['code'] ?? $currentCode));

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

        $conversation = $this->storeLatestExchangeMetadata(
            conversationId: $response->conversationId ?? $conversation->id,
            existingMessageCount: $existingMessageCount,
            assistantReply: $assistantReply,
            assistantMeta: [
                'kind' => $hasCodeChanges ? 'code_suggestion' : 'assistant_reply',
                'response_mode' => $normalizedResponseMode,
                'source_code' => $currentCode,
                'proposed_code' => $assistantCode,
                'diff' => $assistantDiff,
            ],
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

    /**
     * @return array{activeChat: null, pastChats: array<int, array<string, mixed>>}
     */
    public function startNewChat(Flow $flow): array
    {
        $flow->forceFill(['active_chat_conversation_id' => null])->save();

        return [
            'activeChat' => null,
            'pastChats' => $this->pastChatsPayload($flow->fresh()),
        ];
    }

    /**
     * @return array{activeChat: array<string, mixed>, pastChats: array<int, array<string, mixed>>}
     */
    public function compactActiveChat(Flow $flow, User $user, string $currentCode): array
    {
        $activeConversation = $flow->activeChatConversation;

        if (! $activeConversation instanceof AgentConversation) {
            throw ValidationException::withMessages([
                'chat' => 'No active chat to compact.',
            ]);
        }

        $activeConversation->loadMissing('messages');

        if ($activeConversation->messages->isEmpty()) {
            throw ValidationException::withMessages([
                'chat' => 'No active chat to compact.',
            ]);
        }

        $response = FlowChatCompactor::make(
            currentCode: $currentCode,
            messages: $this->conversationMessagesForAgent($activeConversation),
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
                'source_conversation_id' => $activeConversation->id,
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
                ? $this->conversationMessagesForAgent($activeConversation)
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
            'history_strategy' => 'single_user_transcript',
            'request_preview' => [
                'system_prompt' => (string) $assistant->instructions(),
                'history_messages' => array_map(
                    static fn (Message $historyMessage): array => [
                        'role' => $historyMessage->role->value,
                        'content' => $historyMessage->content,
                    ],
                    $activeConversation instanceof AgentConversation
                        ? $this->conversationMessagesForAgent($activeConversation)
                        : [],
                ),
                'user_message' => $message,
                'should_generate_title' => $shouldGenerateTitle,
                'structured_output' => FlowCodeAssistant::schemaPreview($shouldGenerateTitle),
            ],
        ];
    }

    private function resolveActiveConversation(
        Flow $flow,
        User $user,
        string $message,
    ): AgentConversation {
        $activeConversation = $flow->activeChatConversation;

        if ($activeConversation instanceof AgentConversation) {
            $activeConversation->loadMissing('messages');

            return $activeConversation;
        }

        $conversation = $flow->conversations()->create([
            'user_id' => $user->id,
            'title' => Str::limit($message, 100, preserveWords: true),
        ]);

        $flow->forceFill(['active_chat_conversation_id' => $conversation->id])->save();

        return $conversation;
    }

    private function makeFlowCodeAssistant(
        AgentConversation $conversation,
        User $user,
        string $currentCode,
        bool $shouldGenerateTitle,
    ): FlowCodeAssistant {
        return FlowCodeAssistant::make(
            currentCode: $currentCode,
            messages: $this->conversationMessagesForAgent($conversation),
            shouldGenerateTitle: $shouldGenerateTitle,
        )->continue($conversation->id, as: $user);
    }

    /**
     * @param  array<string, mixed>  $assistantMeta
     */
    private function storeLatestExchangeMetadata(
        string $conversationId,
        int $existingMessageCount,
        string $assistantReply,
        array $assistantMeta,
    ): AgentConversation {
        return DB::transaction(function () use (
            $assistantMeta,
            $assistantReply,
            $conversationId,
            $existingMessageCount,
        ): AgentConversation {
            $conversation = AgentConversation::query()
                ->with('messages')
                ->findOrFail($conversationId);

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
     * @param  array<int, array{client_id: string, kind: string, content: string, source_code: string, proposed_code: string}>  $history
     */
    private function appendHistoryMessages(
        AgentConversation $conversation,
        User $user,
        array $history,
    ): AgentConversation {
        $existingClientIds = [];

        foreach ($conversation->messages as $message) {
            $clientId = $message->meta['client_id'] ?? null;

            if (is_string($clientId) && $clientId !== '') {
                $existingClientIds[$clientId] = true;
            }
        }

        $historyWasAppended = false;

        foreach ($history as $entry) {
            if (isset($existingClientIds[$entry['client_id']])) {
                continue;
            }

            $conversation->messages()->create([
                'user_id' => $user->id,
                'agent' => FlowCodeAssistant::class,
                'role' => 'user',
                'content' => $entry['content'],
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => [
                    'client_id' => $entry['client_id'],
                    'kind' => $entry['kind'],
                    'source_code' => $entry['source_code'],
                    'proposed_code' => $entry['proposed_code'],
                ],
            ]);

            $existingClientIds[$entry['client_id']] = true;
            $historyWasAppended = true;
        }

        return $historyWasAppended ? $conversation->fresh('messages') : $conversation;
    }

    /**
     * @return array<int, Message>
     */
    private function conversationMessagesForAgent(
        AgentConversation $conversation,
    ): array {
        $conversation->loadMissing('messages');

        if ($conversation->messages->isEmpty()) {
            return [];
        }

        return [
            new Message('user', $this->buildConversationTranscript($conversation->messages)),
        ];
    }

    /**
     * @param  Collection<int, AgentConversationMessage>  $messages
     */
    private function buildConversationTranscript(Collection $messages): string
    {
        $lines = ['Conversation so far:'];

        foreach ($messages as $message) {
            $speaker = $message->role === 'assistant' ? 'Assistant' : 'User';
            $content = trim($message->content);

            if ($content !== '') {
                $lines[] = sprintf('%s: %s', $speaker, $content);
            }

            $meta = is_array($message->meta) ? $message->meta : [];
            $responseMode = is_string($meta['response_mode'] ?? null)
                ? $meta['response_mode']
                : null;

            if ($message->role === 'assistant' && $responseMode !== null) {
                $lines[] = sprintf('Assistant response mode: %s', $responseMode);
            }

            if ($message->role === 'assistant' && is_string($meta['proposed_code'] ?? null)) {
                $lines[] = 'Assistant proposed code:';
                $lines[] = (string) $meta['proposed_code'];
            }
        }

        return implode("\n", $lines);
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

    /**
     * @param  array<int, array{client_id: string, kind: string, content: string, source_code: string, proposed_code: string}>  $history
     * @return array<int, array{client_id: string, kind: string, content: string, source_code: string, proposed_code: string}>
     */
    private function sanitizeHistory(array $history): array
    {
        $sanitizedHistory = [];

        foreach ($history as $entry) {
            $clientId = trim((string) ($entry['client_id'] ?? ''));
            $kind = (string) ($entry['kind'] ?? '');

            if ($clientId === '' || ! in_array($kind, self::SUPPORTED_HISTORY_KINDS, true)) {
                continue;
            }

            $content = trim($this->sanitizeUtf8((string) ($entry['content'] ?? '')));

            if ($content === '') {
                continue;
            }

            $sanitizedHistory[] = [
                'client_id' => $clientId,
                'kind' => $kind,
                'content' => $content,
                'source_code' => $this->sanitizeUtf8((string) ($entry['source_code'] ?? '')),
                'proposed_code' => $this->sanitizeUtf8((string) ($entry['proposed_code'] ?? '')),
            ];
        }

        return $sanitizedHistory;
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
}
