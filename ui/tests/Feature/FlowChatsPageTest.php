<?php

use App\Models\AgentConversation;
use App\Models\Flow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows a paginated archived chat list', function () {
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();

    $archivedChats = collect(range(1, 17))->map(function (int $index) use ($flow, $user) {
        $conversation = AgentConversation::query()->create([
            'user_id' => $user->id,
            'flow_id' => $flow->id,
            'title' => "Archived chat {$index}",
        ]);

        $conversation->timestamps = false;
        $conversation->forceFill([
            'created_at' => now()->subMinutes(17 - $index),
            'updated_at' => now()->subMinutes(17 - $index),
        ])->save();
        $conversation->timestamps = true;

        $conversation->messages()->create([
            'user_id' => $user->id,
            'agent' => 'tests.agent',
            'role' => 'assistant',
            'content' => "Reply {$index}",
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ]);

        return $conversation;
    });

    $activeChat = AgentConversation::query()->create([
        'user_id' => $user->id,
        'flow_id' => $flow->id,
        'title' => 'Active chat',
    ]);

    $activeChat->messages()->create([
        'user_id' => $user->id,
        'agent' => 'tests.agent',
        'role' => 'assistant',
        'content' => 'Active reply',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    $flow->forceFill([
        'active_chat_conversation_id' => $activeChat->id,
    ])->save();

    $response = $this->actingAs($user)->get(route('flows.chat.index', [
        'flow' => $flow,
        'page' => 2,
    ]));

    $response->assertSuccessful();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('flows/Chats')
        ->where('flow.id', $flow->id)
        ->where('sorting.column', 'updated_at')
        ->where('sorting.direction', 'desc')
        ->where('chats.current_page', 2)
        ->where('chats.last_page', 2)
        ->has('chats.data', 2)
        ->where('chats.data.0.id', $archivedChats->firstWhere('title', 'Archived chat 2')?->id)
        ->where('chats.data.1.id', $archivedChats->firstWhere('title', 'Archived chat 1')?->id)
    );
});

it('applies archived chat search filters', function () {
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();

    $alphaConversation = AgentConversation::query()->create([
        'user_id' => $user->id,
        'flow_id' => $flow->id,
        'title' => 'Alpha discussion',
    ]);

    $alphaConversation->messages()->create([
        'user_id' => $user->id,
        'agent' => 'tests.agent',
        'role' => 'assistant',
        'content' => 'General archive note',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    $matchingConversation = AgentConversation::query()->create([
        'user_id' => $user->id,
        'flow_id' => $flow->id,
        'title' => 'Deployment ideas',
    ]);

    $matchingConversation->messages()->create([
        'user_id' => $user->id,
        'agent' => 'tests.agent',
        'role' => 'assistant',
        'content' => 'Need target phrase for this archived flow chat',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    $betaConversation = AgentConversation::query()->create([
        'user_id' => $user->id,
        'flow_id' => $flow->id,
        'title' => 'Beta discussion',
    ]);

    $betaConversation->messages()->create([
        'user_id' => $user->id,
        'agent' => 'tests.agent',
        'role' => 'assistant',
        'content' => 'Another message',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    $response = $this->actingAs($user)->get(route('flows.chat.index', [
        'flow' => $flow,
        'search' => 'target phrase',
    ]));

    $response->assertSuccessful();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('flows/Chats')
        ->where('filters.search', 'target phrase')
        ->has('chats.data', 1)
        ->where('chats.data.0.id', $matchingConversation->id)
    );
});

it('applies requested archived chat sorting', function () {
    $user = User::factory()->createOne();
    $flow = Flow::factory()->forUser($user)->createOne();

    $fewMessagesChat = AgentConversation::query()->create([
        'user_id' => $user->id,
        'flow_id' => $flow->id,
        'title' => 'Few messages',
    ]);

    $fewMessagesChat->messages()->create([
        'user_id' => $user->id,
        'agent' => 'tests.agent',
        'role' => 'assistant',
        'content' => 'Only one message',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    $manyMessagesChat = AgentConversation::query()->create([
        'user_id' => $user->id,
        'flow_id' => $flow->id,
        'title' => 'Many messages',
    ]);

    foreach (range(1, 3) as $index) {
        $manyMessagesChat->messages()->create([
            'user_id' => $user->id,
            'agent' => 'tests.agent',
            'role' => 'assistant',
            'content' => "Message {$index}",
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ]);
    }

    $middleMessagesChat = AgentConversation::query()->create([
        'user_id' => $user->id,
        'flow_id' => $flow->id,
        'title' => 'Middle messages',
    ]);

    foreach (range(1, 2) as $index) {
        $middleMessagesChat->messages()->create([
            'user_id' => $user->id,
            'agent' => 'tests.agent',
            'role' => 'assistant',
            'content' => "Middle message {$index}",
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ]);
    }

    $response = $this->actingAs($user)->get(route('flows.chat.index', [
        'flow' => $flow,
        'sort' => 'messages_count',
        'direction' => 'asc',
    ]));

    $response->assertSuccessful();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('flows/Chats')
        ->where('sorting.column', 'messages_count')
        ->where('sorting.direction', 'asc')
        ->where('chats.data.0.id', $fewMessagesChat->id)
        ->where('chats.data.1.id', $middleMessagesChat->id)
        ->where('chats.data.2.id', $manyMessagesChat->id)
    );
});
