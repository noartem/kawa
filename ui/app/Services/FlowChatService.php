<?php

namespace App\Services;

use App\Ai\Agents\FlowChatCompactor;
use App\Ai\Agents\FlowCodeAssistant;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\Flow;
use App\Models\User;
use App\Support\FlowCodeDiff;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Messages\Message;

class FlowChatService
{
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
     * @return array{activeChat: array<string, mixed>, pastChats: array<int, array<string, mixed>>}
     */
    public function sendMessage(
        Flow $flow,
        User $user,
        string $message,
        string $currentCode,
    ): array {
        $conversation = $this->resolveActiveConversation($flow, $user, $message);
        $response = FlowCodeAssistant::make(
            currentCode: $currentCode,
            messages: $this->conversationMessagesForAgent($conversation),
        )->prompt($message);

        $assistantReply = trim((string) ($response['reply'] ?? ''));
        $assistantCode = (string) ($response['code'] ?? $currentCode);
        $hasCodeChanges = $assistantCode !== $currentCode;
        $assistantDiff = $hasCodeChanges
            ? $this->flowCodeDiff->build($currentCode, $assistantCode)
            : null;

        $conversation->messages()->create([
            'user_id' => $user->id,
            'agent' => FlowCodeAssistant::class,
            'role' => 'user',
            'content' => $message,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [
                'kind' => 'prompt',
            ],
        ]);

        $conversation->messages()->create([
            'user_id' => $user->id,
            'agent' => FlowCodeAssistant::class,
            'role' => 'assistant',
            'content' => $assistantReply,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [
                'kind' => 'code_suggestion',
                'source_code' => $currentCode,
                'proposed_code' => $assistantCode,
                'diff' => $assistantDiff,
            ],
        ]);

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

        $title = trim((string) ($response['title'] ?? ''));
        $summary = trim((string) ($response['summary'] ?? ''));

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

    /**
     * @return array<int, Message>
     */
    private function conversationMessagesForAgent(
        AgentConversation $conversation,
    ): array {
        return $conversation->messages
            ->map(function (AgentConversationMessage $message): Message {
                return new Message($message->role, $message->content);
            })
            ->all();
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
            'source_code' => $sourceCode,
            'proposed_code' => $proposedCode,
            'diff' => $diff,
            'has_code_changes' => $proposedCode !== null
                && $sourceCode !== null
                && $proposedCode !== $sourceCode,
        ];
    }
}
