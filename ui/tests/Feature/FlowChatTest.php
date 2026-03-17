<?php

use App\Ai\Agents\FlowChatCompactor;
use App\Ai\Agents\FlowCodeAssistant;
use App\Models\Flow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('sends a flow chat message and stores the assistant suggestion', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("old")',
    ]);

    FlowCodeAssistant::fake([
        [
            'reply' => 'Added the requested greeting update.',
            'code' => 'print("new")',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(route('flows.chat.store', $flow), [
            'message' => 'Update the greeting output',
            'current_code' => 'print("old")',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.title', 'Update the greeting output')
        ->assertJsonPath('activeChat.messages_count', 2)
        ->assertJsonPath('activeChat.messages.0.role', 'user')
        ->assertJsonPath('activeChat.messages.1.role', 'assistant')
        ->assertJsonPath('activeChat.messages.1.kind', 'code_suggestion')
        ->assertJsonPath('activeChat.messages.1.source_code', 'print("old")')
        ->assertJsonPath('activeChat.messages.1.proposed_code', 'print("new")')
        ->assertJsonPath('activeChat.messages.1.has_code_changes', true)
        ->assertJsonCount(0, 'pastChats');

    $flow->refresh();

    expect($flow->active_chat_conversation_id)->not->toBeNull();

    $conversation = $flow->activeChatConversation()->firstOrFail();

    expect($conversation->messages)->toHaveCount(2)
        ->and($conversation->messages[0]->content)->toBe('Update the greeting output')
        ->and($conversation->messages[1]->content)->toBe('Added the requested greeting update.')
        ->and($conversation->messages[1]->meta['proposed_code'])->toBe('print("new")');

    FlowCodeAssistant::assertPrompted('Update the greeting output');
});

it('archives the current active chat when starting a new chat', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();

    $conversation = $flow->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Original thread',
    ]);

    $conversation->messages()->createMany([
        [
            'user_id' => $user->id,
            'agent' => FlowCodeAssistant::class,
            'role' => 'user',
            'content' => 'First request',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => ['kind' => 'prompt'],
        ],
        [
            'user_id' => $user->id,
            'agent' => FlowCodeAssistant::class,
            'role' => 'assistant',
            'content' => 'First answer',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => ['kind' => 'code_suggestion'],
        ],
    ]);

    $flow->update([
        'active_chat_conversation_id' => $conversation->id,
    ]);

    actingAs($user)
        ->postJson(route('flows.chat.new', $flow))
        ->assertSuccessful()
        ->assertJsonPath('activeChat', null)
        ->assertJsonPath('pastChats.0.id', $conversation->id)
        ->assertJsonPath('pastChats.0.title', 'Original thread')
        ->assertJsonPath('pastChats.0.messages_count', 2);

    expect($flow->fresh()->active_chat_conversation_id)->toBeNull();
});

it('compacts the active chat into a new active summary conversation', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("current")',
    ]);

    $conversation = $flow->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Long implementation thread',
    ]);

    $conversation->messages()->createMany([
        [
            'user_id' => $user->id,
            'agent' => FlowCodeAssistant::class,
            'role' => 'user',
            'content' => 'Add retries to the flow',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => ['kind' => 'prompt'],
        ],
        [
            'user_id' => $user->id,
            'agent' => FlowCodeAssistant::class,
            'role' => 'assistant',
            'content' => 'Retries were added around the dispatch call.',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => ['kind' => 'code_suggestion'],
        ],
    ]);

    $flow->update([
        'active_chat_conversation_id' => $conversation->id,
    ]);

    FlowChatCompactor::fake([
        [
            'title' => 'Retry follow-up',
            'summary' => 'The flow now retries failed dispatches. Continue from the stabilized retry logic.',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(route('flows.chat.compact', $flow), [
            'current_code' => 'print("current")',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.title', 'Retry follow-up')
        ->assertJsonPath('activeChat.messages_count', 1)
        ->assertJsonPath('activeChat.messages.0.kind', 'compact_summary')
        ->assertJsonPath('pastChats.0.id', $conversation->id);

    $flow->refresh();
    $activeConversation = $flow->activeChatConversation()->firstOrFail();

    expect($activeConversation->id)->not->toBe($conversation->id)
        ->and($activeConversation->title)->toBe('Retry follow-up')
        ->and($activeConversation->messages)->toHaveCount(1)
        ->and($activeConversation->messages[0]->meta['kind'])->toBe('compact_summary')
        ->and($activeConversation->messages[0]->meta['source_conversation_id'])->toBe($conversation->id);

    FlowChatCompactor::assertPrompted('Compress this chat into a fresh continuation summary.');
});

it('includes active and past chats in the editor payload', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();

    $activeConversation = $flow->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Active thread',
    ]);

    $activeConversation->messages()->create([
        'user_id' => $user->id,
        'agent' => FlowCodeAssistant::class,
        'role' => 'assistant',
        'content' => 'Current active suggestion',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [
            'kind' => 'code_suggestion',
            'source_code' => 'print("before")',
            'proposed_code' => 'print("after")',
            'diff' => '-print("before")\n+print("after")',
        ],
    ]);

    $archivedConversation = $flow->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Archived thread',
    ]);

    $archivedConversation->messages()->create([
        'user_id' => $user->id,
        'agent' => FlowChatCompactor::class,
        'role' => 'assistant',
        'content' => 'Archived summary',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => ['kind' => 'compact_summary'],
    ]);

    $flow->update([
        'active_chat_conversation_id' => $activeConversation->id,
    ]);

    actingAs($user)
        ->get(route('flows.show', $flow))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->where('activeChat.id', $activeConversation->id)
            ->where('activeChat.messages.0.kind', 'code_suggestion')
            ->where('activeChat.messages.0.source_code', 'print("before")')
            ->where('activeChat.messages.0.proposed_code', 'print("after")')
            ->where('pastChats.0.id', $archivedConversation->id)
            ->where('pastChats.0.messages.0.kind', 'compact_summary')
        );
});
