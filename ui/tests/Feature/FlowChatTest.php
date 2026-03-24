<?php

use App\Ai\Agents\FlowChatCompactor;
use App\Ai\Agents\FlowCodeAssistant;
use App\Models\Flow;
use App\Models\User;
use App\Services\FlowChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\AiException;
use Mockery\MockInterface;

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
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
            'title' => 'Greeting update',
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
        ->assertJsonPath('activeChat.title', 'Greeting update')
        ->assertJsonPath('activeChat.messages_count', 2)
        ->assertJsonPath('activeChat.messages.0.role', 'user')
        ->assertJsonPath('activeChat.messages.1.role', 'assistant')
        ->assertJsonPath('activeChat.messages.1.kind', 'code_suggestion')
        ->assertJsonPath('activeChat.messages.1.response_mode', FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE)
        ->assertJsonPath('activeChat.messages.1.source_code', 'print("old")')
        ->assertJsonPath('activeChat.messages.1.proposed_code', 'print("new")')
        ->assertJsonPath('activeChat.messages.1.has_code_changes', true)
        ->assertJsonCount(0, 'pastChats');

    $flow->refresh();

    expect($flow->active_chat_conversation_id)->not->toBeNull();

    $conversation = $flow->activeChatConversation()->firstOrFail();

    expect($conversation->messages)->toHaveCount(2)
        ->and($conversation->title)->toBe('Greeting update')
        ->and($conversation->messages[0]->content)->toBe('Update the greeting output')
        ->and($conversation->messages[1]->content)->toBe('Added the requested greeting update.')
        ->and($conversation->messages[1]->meta['proposed_code'])->toBe('print("new")');

    FlowCodeAssistant::assertPrompted('Update the greeting output');
});

it('stores a message-only assistant reply without marking code changes', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("same")',
    ]);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY,
            'title' => 'Behavior check',
            'reply' => 'The flow already prints the expected value, so no change is needed.',
            'code' => 'print("same")',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(route('flows.chat.store', $flow), [
            'message' => 'Does this flow already do the right thing?',
            'current_code' => 'print("same")',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.messages.1.kind', 'assistant_reply')
        ->assertJsonPath('activeChat.messages.1.response_mode', FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY)
        ->assertJsonPath('activeChat.messages.1.has_code_changes', false)
        ->assertJsonPath('activeChat.messages.1.diff', null);

    $conversation = $flow->fresh()->activeChatConversation()->firstOrFail();

    expect($conversation->messages[1]->meta['kind'])->toBe('assistant_reply')
        ->and($conversation->messages[1]->meta['response_mode'])->toBe(FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY)
        ->and($conversation->title)->toBe('Behavior check');
});

it('accepts chat requests for flows with empty code', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => '',
    ]);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
            'title' => 'Start flow',
            'reply' => 'I created a minimal Flow scaffold.',
            'code' => 'from kawa import actor, event, Context',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(route('flows.chat.store', $flow), [
            'message' => 'Create a minimal flow',
            'current_code' => '',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.title', 'Start flow')
        ->assertJsonPath('activeChat.messages.1.kind', 'code_suggestion')
        ->assertJsonPath('activeChat.messages.1.source_code', '')
        ->assertJsonPath(
            'activeChat.messages.1.proposed_code',
            'from kawa import actor, event, Context',
        );

    $prompt = (new FlowCodeAssistant(''))->instructions();

    expect((string) $prompt)->toContain('# The Flow code is currently empty.');
});

it('ignores chat code changes when the difference is only surrounding whitespace', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("same")',
    ]);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
            'title' => 'Whitespace only',
            'reply' => 'No real code change is needed.',
            'code' => "\nprint(\"same\")\n",
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(route('flows.chat.store', $flow), [
            'message' => 'Normalize whitespace only',
            'current_code' => 'print("same")',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.messages.1.kind', 'assistant_reply')
        ->assertJsonPath('activeChat.messages.1.has_code_changes', false)
        ->assertJsonPath('activeChat.messages.1.diff', null)
        ->assertJsonPath('activeChat.messages.1.proposed_code', 'print("same")');

    $conversation = $flow->fresh()->activeChatConversation()->firstOrFail();

    expect($conversation->messages[1]->meta['kind'])->toBe('assistant_reply')
        ->and($conversation->messages[1]->meta['diff'])->toBeNull()
        ->and($conversation->messages[1]->meta['proposed_code'])->toBe('print("same")');
});

it('keeps the existing chat title on subsequent assistant responses', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("old")',
    ]);

    $conversation = $flow->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Existing title',
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
            'meta' => [
                'kind' => 'assistant_reply',
                'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY,
            ],
        ],
    ]);

    $flow->update([
        'active_chat_conversation_id' => $conversation->id,
    ]);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
            'title' => null,
            'reply' => 'I added the next change.',
            'code' => 'print("new")',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(route('flows.chat.store', $flow), [
            'message' => 'Apply the next update',
            'current_code' => 'print("old")',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.title', 'Existing title');

    expect($flow->fresh()->activeChatConversation()->firstOrFail()->title)
        ->toBe('Existing title');
});

it('returns a friendly provider unavailable error for upstream ai 503 failures', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();

    app()->instance(
        FlowChatService::class,
        \Mockery::mock(FlowChatService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->andThrow(new AiException('OpenAI Error [503]: Unknown error', 503));
        }),
    );

    actingAs($user)
        ->postJson(route('flows.chat.store', $flow), [
            'message' => 'Try again',
            'current_code' => 'print("same")',
        ])
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_provider_unavailable')
        ->assertJsonPath(
            'message',
            'The AI provider is temporarily unavailable. Please try again in a minute.',
        );
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

it('renders a csrf meta tag on the flow editor page', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();

    actingAs($user)
        ->get(route('flows.show', $flow))
        ->assertSuccessful()
        ->assertSee('meta name="csrf-token"', false)
        ->assertSee(csrf_token(), false);
});

it('renders a local chat debug page with the composed llm payload', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("debug")',
    ]);

    $conversation = $flow->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Debug thread',
    ]);

    $conversation->messages()->createMany([
        [
            'user_id' => $user->id,
            'agent' => FlowCodeAssistant::class,
            'role' => 'user',
            'content' => 'Explain this flow',
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
            'content' => 'It prints a debug marker.',
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
        ->get(route('flows.chat.debug', [
            'flow' => $flow,
            'message' => 'Summarize the current flow',
            'current_code' => 'print("preview")',
            'should_generate_title' => true,
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flows/ChatDebug')
            ->where('flow.id', $flow->id)
            ->where('preview.provider', 'openai')
            ->where('preview.model', FlowCodeAssistant::MODEL)
            ->where('preview.should_generate_title', true)
            ->where('preview.user_message', 'Summarize the current flow')
            ->where('preview.current_code', 'print("preview")')
            ->where('preview.history_strategy', 'single_user_transcript')
            ->where('preview.schema.title_generation', 'fixed mode for this request: generate_title')
            ->where('preview.schema.response_mode', 'required enum: message_only | message_with_code')
            ->where('preview.active_conversation.id', $conversation->id)
            ->where('preview.history.0.content', 'Explain this flow')
            ->where('preview.history.1.content', 'It prints a debug marker.')
            ->where('preview.request_preview.history_messages.0.role', 'user')
            ->where(
                'preview.request_preview.history_messages.0.content',
                fn (string $content): bool => str_contains($content, 'User: Explain this flow')
                    && str_contains($content, 'Assistant: It prints a debug marker.'),
            )
            ->where('preview.request_preview.user_message', 'Summarize the current flow')
            ->where('preview.request_preview.should_generate_title', true)
        );
});
