<?php

use App\Ai\Agents\FlowChatCompactor;
use App\Ai\Agents\FlowCodeAssistant;
use App\Jobs\ProcessFlowChatRequest;
use App\Models\AgentConversationMessage;
use App\Models\Flow;
use App\Models\FlowChatRequestStatus;
use App\Models\User;
use App\Services\FlowChatService;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;
use Mockery\MockInterface;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function createChatForFlow(User $user, Flow $flow): string
{
    $response = actingAs($user)->postJson(route('flows.chat.store', $flow));

    $response
        ->assertSuccessful()
        ->assertJsonPath('activeChat.messages_count', 0);

    return (string) $response->json('activeChat.id');
}

function flowChatMessageRoute(Flow $flow, string $chatId): string
{
    return route('flows.chat.messages.store', [
        'flow' => $flow,
        'chat' => $chatId,
    ]);
}

function flowChatRequestRoute(Flow $flow, string $chatId, FlowChatRequestStatus $chatRequest): string
{
    return route('flows.chat.messages.requests.show', [
        'flow' => $flow,
        'chat' => $chatId,
        'chatRequest' => $chatRequest,
    ]);
}

it('sends a flow chat message and stores the assistant suggestion', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("old")',
    ]);
    $chatId = createChatForFlow($user, $flow);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
            'title' => 'Greeting update',
            'reply' => 'Added the requested greeting update.',
            'code' => 'print("new")',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $chatId), [
            'message' => 'Update the greeting output',
            'current_code' => 'print("old")',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.title', 'Greeting update')
        ->assertJsonPath('activeChat.messages_count', 2)
        ->assertJsonPath('activeChat.messages.0.role', 'user')
        ->assertJsonPath('activeChat.messages.0.kind', 'prompt')
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
        ->and($conversation->messages[0]->meta['kind'])->toBe('prompt')
        ->and($conversation->messages[1]->content)->toBe('Added the requested greeting update.')
        ->and($conversation->messages[1]->meta['proposed_code'])->toBe('print("new")');

    FlowCodeAssistant::assertPrompted(function (AgentPrompt $prompt) use ($conversation): bool {
        if (! $prompt->agent instanceof FlowCodeAssistant) {
            return false;
        }

        return $prompt->prompt === 'Update the greeting output'
            && $prompt->agent->currentConversation() === $conversation->id;
    });
});

it('uses chat completions transport for runtime chat requests and persists the exchange', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("first")',
    ]);

    $conversation = $flow->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Runtime transport',
    ]);

    $conversation->messages()->createMany([
        [
            'user_id' => $user->id,
            'agent' => FlowCodeAssistant::class,
            'role' => 'user',
            'content' => 'What does this flow do?',
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
            'content' => 'It prints a value.',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [
                'kind' => 'assistant_reply',
                'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY,
                'source_code' => 'print("first")',
                'proposed_code' => 'print("first")',
            ],
        ],
        [
            'user_id' => $user->id,
            'agent' => FlowCodeAssistant::class,
            'role' => 'user',
            'content' => 'What should I fix first?',
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
            'content' => 'Fix the first obvious issue.',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [
                'kind' => 'assistant_reply',
                'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY,
                'source_code' => 'print("first")',
                'proposed_code' => 'print("first")',
            ],
        ],
    ]);

    $flow->update([
        'active_chat_conversation_id' => $conversation->id,
    ]);

    config()->set('ai.providers.openai.url', 'https://routerai.ru/api/v1');
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('ai.chat.max_history_messages', 2);

    Queue::fake();

    Http::fake([
        'https://routerai.ru/api/v1/chat/completions' => Http::response([
            'model' => FlowCodeAssistant::MODEL,
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 12,
                'total_tokens' => 22,
            ],
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY,
                            'title' => null,
                            'reply' => 'It prints the current value and has no obvious issues.',
                            'code' => 'print("first")',
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
        ]),
    ]);

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $conversation->id), [
            'message' => 'Summarize the current flow and check for obvious issues.',
            'current_code' => 'print("first")',
        ])
        ->assertAccepted()
        ->assertJsonPath('status', FlowChatRequestStatus::STATUS_PENDING)
        ->assertJsonPath('activeChat.messages_count', 4)
        ->assertJsonPath('chatRequest.status', FlowChatRequestStatus::STATUS_PENDING);

    Queue::assertPushed(ProcessFlowChatRequest::class);

    /** @var FlowChatRequestStatus $chatRequest */
    $chatRequest = FlowChatRequestStatus::query()->firstOrFail();

    expect($chatRequest->message)
        ->toBe('Summarize the current flow and check for obvious issues.')
        ->and($chatRequest->status)->toBe(FlowChatRequestStatus::STATUS_PENDING);

    app(FlowChatService::class)->processQueuedMessage($chatRequest->id);

    actingAs($user)
        ->getJson(flowChatRequestRoute($flow, $conversation->id, $chatRequest->fresh()))
        ->assertSuccessful()
        ->assertJsonPath('status', FlowChatRequestStatus::STATUS_COMPLETED)
        ->assertJsonPath('activeChat.messages_count', 6)
        ->assertJsonPath('activeChat.messages.4.kind', 'prompt')
        ->assertJsonPath('activeChat.messages.5.kind', 'assistant_reply')
        ->assertJsonPath(
            'activeChat.messages.5.content',
            'It prints the current value and has no obvious issues.',
        );

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return $request->method() === 'POST'
            && $request->url() === 'https://routerai.ru/api/v1/chat/completions'
            && ($payload['model'] ?? null) === FlowCodeAssistant::MODEL
            && ($payload['messages'][0]['role'] ?? null) === 'system'
            && count($payload['messages'] ?? []) === 4
            && ($payload['messages'][1]['content'] ?? null) === 'What should I fix first?'
            && str_contains((string) ($payload['messages'][2]['content'] ?? ''), 'Fix the first obvious issue.')
            && ($payload['messages'][3]['content'] ?? null) === 'Summarize the current flow and check for obvious issues.'
            && ($payload['response_format']['type'] ?? null) === 'json_schema';
    });

    $storedConversation = $flow->fresh()->activeChatConversation()->firstOrFail();

    expect($storedConversation->messages)->toHaveCount(6)
        ->and($storedConversation->messages[4]->content)
        ->toBe('Summarize the current flow and check for obvious issues.')
        ->and($storedConversation->messages[4]->meta['kind'])->toBe('prompt')
        ->and($storedConversation->messages[5]->content)
        ->toBe('It prints the current value and has no obvious issues.')
        ->and($storedConversation->messages[5]->meta['provider'])->toBe('openai')
        ->and($storedConversation->messages[5]->meta['model'])->toBe(FlowCodeAssistant::MODEL)
        ->and($storedConversation->messages[5]->usage['total_tokens'])->toBe(22);
});

it('stores a message-only assistant reply without marking code changes', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("same")',
    ]);
    $chatId = createChatForFlow($user, $flow);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY,
            'title' => 'Behavior check',
            'reply' => 'The flow already prints the expected value, so no change is needed.',
            'code' => 'print("same")',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $chatId), [
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

it('ignores empty code suggestions instead of proposing to wipe the editor', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("keep me")',
    ]);
    $chatId = createChatForFlow($user, $flow);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
            'title' => 'Cleanup',
            'reply' => 'I cleaned this up.',
            'code' => '',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $chatId), [
            'message' => 'Refactor this flow',
            'current_code' => 'print("keep me")',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.messages.1.kind', 'assistant_reply')
        ->assertJsonPath('activeChat.messages.1.has_code_changes', false)
        ->assertJsonPath('activeChat.messages.1.diff', null)
        ->assertJsonPath('activeChat.messages.1.proposed_code', 'print("keep me")');

    $conversation = $flow->fresh()->activeChatConversation()->firstOrFail();

    expect($conversation->messages[1]->meta['kind'])->toBe('assistant_reply')
        ->and($conversation->messages[1]->meta['proposed_code'])->toBe('print("keep me")')
        ->and($conversation->messages[1]->meta['diff'])->toBeNull();
});

it('accepts chat requests for flows with empty code', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => '',
    ]);
    $chatId = createChatForFlow($user, $flow);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
            'title' => 'Start flow',
            'reply' => 'I created a minimal Flow scaffold.',
            'code' => 'from kawa import actor, event, Context',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $chatId), [
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

    expect((string) $prompt)
        ->toContain('`title_generation` for this request is: `skip_title`')
        ->toContain('# The Flow code is currently empty.')
        ->toContain('uv script')
        ->toContain('PEP 723')
        ->toContain('# /// script')
        ->toContain('dependencies')
        ->toContain('ctx.storage.get(key, default)')
        ->toContain('Webhook.by("slug")')
        ->toContain('imported from `kawa.email`')
        ->toContain('do not write `async def` actors')
        ->toContain('Treat the `Current code` section as the source of truth')
        ->toContain('describe the code as a proposal for the user to review');

    expect(resource_path('prompts/flow-code-assistant.md'))->toBeFile();
});

it('ignores chat code changes when the difference is only surrounding whitespace', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("same")',
    ]);
    $chatId = createChatForFlow($user, $flow);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
            'title' => 'Whitespace only',
            'reply' => 'No real code change is needed.',
            'code' => "\nprint(\"same\")\n",
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $chatId), [
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

it('sanitizes malformed utf-8 from assistant chat responses before persisting', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("old")',
    ]);
    $chatId = createChatForFlow($user, $flow);

    $invalidUtf8 = chr(0xB1);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
            'title' => "Greeting{$invalidUtf8} update",
            'reply' => "Added the requested{$invalidUtf8} greeting update.",
            'code' => "print(\"new\"){$invalidUtf8}",
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $chatId), [
            'message' => 'Update the greeting output',
            'current_code' => 'print("old")',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.title', 'Greeting update')
        ->assertJsonPath('activeChat.messages.1.content', 'Added the requested greeting update.')
        ->assertJsonPath('activeChat.messages.1.proposed_code', 'print("new")');

    $conversation = $flow->fresh()->activeChatConversation()->firstOrFail();

    expect($conversation->title)->toBe('Greeting update')
        ->and($conversation->messages[1]->content)->toBe('Added the requested greeting update.')
        ->and($conversation->messages[1]->meta['proposed_code'])->toBe('print("new")')
        ->and($conversation->messages[1]->meta['diff'])->toBe('-print("old")'."\n".'+print("new")');
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
        ->postJson(flowChatMessageRoute($flow, $conversation->id), [
            'message' => 'Apply the next update',
            'current_code' => 'print("old")',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.title', 'Existing title');

    expect($flow->fresh()->activeChatConversation()->firstOrFail()->title)
        ->toBe('Existing title');
});

it('continues an existing chat with the remembered conversation id', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("old")',
    ]);
    $chatId = createChatForFlow($user, $flow);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
            'title' => 'Greeting update',
            'reply' => 'Added the requested greeting update.',
            'code' => 'print("first")',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $chatId), [
            'message' => 'Update the greeting output',
            'current_code' => 'print("old")',
        ])
        ->assertSuccessful();

    $conversationId = $flow->fresh()->active_chat_conversation_id;

    expect($conversationId)->not->toBeNull();

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY,
            'title' => null,
            'reply' => 'The earlier greeting update is still in place.',
            'code' => 'print("first")',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $conversationId), [
            'message' => 'What changed in the previous step?',
            'current_code' => 'print("first")',
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.id', $conversationId)
        ->assertJsonPath('activeChat.messages_count', 4)
        ->assertJsonPath('activeChat.messages.2.kind', 'prompt')
        ->assertJsonPath('activeChat.messages.3.kind', 'assistant_reply')
        ->assertJsonPath('activeChat.messages.3.content', 'The earlier greeting update is still in place.');

    $conversation = $flow->fresh()->activeChatConversation()->firstOrFail();

    expect($conversation->id)->toBe($conversationId)
        ->and($conversation->messages)->toHaveCount(4)
        ->and($conversation->messages[2]->content)->toBe('What changed in the previous step?')
        ->and($conversation->messages[2]->meta['kind'])->toBe('prompt')
        ->and($conversation->messages[3]->content)->toBe('The earlier greeting update is still in place.')
        ->and($conversation->messages[3]->meta['kind'])->toBe('assistant_reply');

    FlowCodeAssistant::assertPrompted(function (AgentPrompt $prompt) use ($conversationId): bool {
        if ($prompt->prompt !== 'What changed in the previous step?') {
            return false;
        }

        expect($prompt->agent)->toBeInstanceOf(FlowCodeAssistant::class);

        /** @var FlowCodeAssistant $assistant */
        $assistant = $prompt->agent;

        expect($assistant->currentConversation())->toBe($conversationId)
            ->and($assistant->messages())->toHaveCount(2)
            ->and($assistant->messages()[0]->role->value)->toBe('user')
            ->and($assistant->messages()[0]->content)->toBe('Update the greeting output')
            ->and($assistant->messages()[1]->role->value)->toBe('assistant')
            ->and($assistant->messages()[1]->content)->toContain('Added the requested greeting update.')
            ->toContain('Response mode: message_with_code')
            ->toContain('The current code matches this previously suggested change.')
            ->toContain('Use the current code as the source of truth.');

        expect($assistant->messages()[1]->content)
            ->not->toContain('Proposed code:')
            ->not->toContain('print("first")');

        return true;
    });
});

it('ignores frontend history payloads and relies on stored chat plus current code', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne([
        'code' => 'print("first")',
    ]);

    $conversation = $flow->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Retry thread',
    ]);

    $conversation->messages()->createMany([
        [
            'user_id' => $user->id,
            'agent' => FlowCodeAssistant::class,
            'role' => 'user',
            'content' => 'Update the greeting output',
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
            'content' => 'Added the requested greeting update.',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [
                'kind' => 'code_suggestion',
                'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
                'proposed_code' => 'print("first")',
            ],
        ],
    ]);

    $flow->update([
        'active_chat_conversation_id' => $conversation->id,
    ]);

    FlowCodeAssistant::fake([
        [
            'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY,
            'reply' => 'The change is still applied.',
            'code' => 'print("first")',
        ],
    ])->preventStrayPrompts();

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $conversation->id), [
            'message' => 'What changed in the previous step?',
            'current_code' => 'print("first")',
            'history' => [
                [
                    'client_id' => 'apply-history-1',
                    'kind' => 'apply_proposal',
                    'content' => 'Applied the suggested code to the editor.',
                    'source_code' => 'print("old")',
                    'proposed_code' => 'print("first")',
                ],
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('activeChat.messages_count', 4)
        ->assertJsonPath('activeChat.messages.2.kind', 'prompt')
        ->assertJsonPath('activeChat.messages.3.kind', 'assistant_reply');

    $conversation = $flow->fresh()->activeChatConversation()->firstOrFail();

    expect($conversation->messages)->toHaveCount(4)
        ->and(
            $conversation->messages
                ->pluck('meta')
                ->filter()
                ->map(fn (array $meta): ?string => $meta['kind'] ?? null)
                ->values()
                ->all(),
        )
        ->not->toContain('apply_proposal');

    FlowCodeAssistant::assertPrompted(function (AgentPrompt $prompt) use ($conversation): bool {
        if (! $prompt->agent instanceof FlowCodeAssistant) {
            return false;
        }

        /** @var FlowCodeAssistant $assistant */
        $assistant = $prompt->agent;

        if ($assistant->currentConversation() !== $conversation->id) {
            return false;
        }

        expect($assistant->messages())->toHaveCount(2)
            ->and($assistant->messages()[1]->content)->toContain('The current code matches this previously suggested change.')
            ->toContain('Use the current code as the source of truth.');

        return true;
    });
});

it('returns a friendly provider unavailable error for upstream ai 503 failures', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();
    $chatId = createChatForFlow($user, $flow);

    app()->instance(
        FlowChatService::class,
        Mockery::mock(FlowChatService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitMessage')
                ->once()
                ->andThrow(new AiException('OpenAI Error [503]: Unknown error', 503));
        }),
    );

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $chatId), [
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

it('returns a specific error when chat persistence hits a json encoding failure', function () {
    /** @var User $user */
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();
    $chatId = createChatForFlow($user, $flow);

    app()->instance(
        FlowChatService::class,
        Mockery::mock(FlowChatService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitMessage')
                ->once()
                ->andThrow(JsonEncodingException::forAttribute(
                    new AgentConversationMessage,
                    'meta',
                    'Malformed UTF-8 characters, possibly incorrectly encoded',
                ));
        }),
    );

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $chatId), [
            'message' => 'Try again',
            'current_code' => 'print("same")',
        ])
        ->assertStatus(500)
        ->assertJsonPath('code', 'chat_response_encoding_failed')
        ->assertJsonPath(
            'message',
            'The chat response could not be saved cleanly. Please try again.',
        );
});

it('creates a new empty active chat and archives the previous active chat', function () {
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

    $response = actingAs($user)
        ->postJson(route('flows.chat.store', $flow))
        ->assertSuccessful()
        ->assertJsonPath('activeChat.title', 'New chat')
        ->assertJsonPath('activeChat.messages_count', 0)
        ->assertJsonPath('pastChats.0.id', $conversation->id)
        ->assertJsonPath('pastChats.0.title', 'Original thread')
        ->assertJsonPath('pastChats.0.messages_count', 2);

    $newConversationId = (string) $response->json('activeChat.id');
    $activeConversation = $flow->fresh()->activeChatConversation()->firstOrFail();

    expect($newConversationId)->not->toBe($conversation->id)
        ->and($activeConversation->id)->toBe($newConversationId)
        ->and($activeConversation->title)->toBe('New chat')
        ->and($activeConversation->messages)->toHaveCount(0);
});

it('returns 404 when posting a message to a chat from another flow', function () {
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();
    $otherFlow = Flow::factory()->forUser($user)->createOne();
    $otherConversation = $otherFlow->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Other flow chat',
    ]);

    actingAs($user)
        ->postJson(flowChatMessageRoute($flow, $otherConversation->id), [
            'message' => 'Cross-flow access',
            'current_code' => 'print("old")',
        ])
        ->assertNotFound();
});

it('does not register legacy chat post routes', function () {
    $postRoutes = collect(app('router')->getRoutes()->getRoutesByMethod()['POST'] ?? [])
        ->map(fn ($route) => $route->uri())
        ->values();

    expect($postRoutes)
        ->not->toContain('flows/{flow}/chats/new')
        ->not->toContain('flows/{flow}/chats/compact');
});

it('rejects legacy chat post endpoints', function () {
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();

    actingAs($user)
        ->post('/flows/'.$flow->id.'/chats/new')
        ->assertMethodNotAllowed();

    actingAs($user)
        ->post('/flows/'.$flow->id.'/chats/compact')
        ->assertMethodNotAllowed();
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
        ->postJson(route('flows.chat.compact', [
            'flow' => $flow,
            'chat' => $conversation,
        ]), [
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

it('returns 404 when compacting a chat from another flow', function () {
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();
    $otherFlow = Flow::factory()->forUser($user)->createOne();
    $otherConversation = $otherFlow->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Other flow chat',
    ]);

    actingAs($user)
        ->postJson(route('flows.chat.compact', [
            'flow' => $flow,
            'chat' => $otherConversation,
        ]), [
            'current_code' => 'print("current")',
        ])
        ->assertNotFound();
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
            ->component('flows/Show')
            ->where('allChatsUrl', route('flows.chat.index', $flow))
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
            ->where('preview.history_strategy', 'role_preserving_messages')
            ->where('preview.schema.title_generation', 'fixed mode for this request: generate_title')
            ->where('preview.schema.response_mode', 'required enum: message_only | message_with_code')
            ->where('preview.active_conversation.id', $conversation->id)
            ->where('preview.active_conversation.memory_strategy', 'continue')
            ->where('preview.history.0.content', 'Explain this flow')
            ->where('preview.history.1.content', 'It prints a debug marker.')
            ->where('preview.request_preview.history_messages.0.role', 'user')
            ->where('preview.request_preview.history_messages.0.content', 'Explain this flow')
            ->where('preview.request_preview.history_messages.1.role', 'assistant')
            ->where('preview.request_preview.history_messages.1.content', 'It prints a debug marker.')
            ->where('preview.request_preview.user_message', 'Summarize the current flow')
            ->where('preview.request_preview.should_generate_title', true)
        );
});
