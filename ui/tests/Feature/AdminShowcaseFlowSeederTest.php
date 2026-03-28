<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminShowcaseFlowSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_showcase_seeder_creates_a_rich_admin_flow(): void
    {
        User::factory()->admin()->withoutTwoFactor()->create([
            'name' => 'Admin',
            'email' => 'admin@kawaflow.localhost',
            'password' => bcrypt('12345678'),
        ]);

        $this->seed(\Database\Seeders\AdminShowcaseFlowSeeder::class);

        $flow = Flow::query()
            ->with(['histories', 'runs.logs', 'conversations.messages'])
            ->where('slug', 'admin-showcase-flow')
            ->first();

        $this->assertNotNull($flow);
        $this->assertSame('Operations Showcase Flow', $flow->name);
        $this->assertSame('Europe/Moscow', $flow->timezone);
        $this->assertSame('stopped', $flow->status);
        $this->assertCount(2, $flow->histories);
        $this->assertCount(3, $flow->runs);
        $this->assertCount(2, $flow->conversations);
        $this->assertNotNull($flow->active_chat_conversation_id);

        /** @var FlowRun|null $latestDevelopmentRun */
        $latestDevelopmentRun = $flow->runs
            ->where('type', 'development')
            ->sortByDesc('created_at')
            ->first();

        $this->assertNotNull($latestDevelopmentRun);
        $this->assertSame('stopped', $latestDevelopmentRun->status);
        $this->assertFalse($latestDevelopmentRun->active);
        $this->assertSame('seeded-dev-v3', $latestDevelopmentRun->meta['graph_hash'] ?? null);
        $this->assertSame(
            'ScheduleCollector',
            $latestDevelopmentRun->graph_snapshot['actors'][0]['id'] ?? null,
        );
        $this->assertSame(
            'CronEvent.by("*/15 * * * *")',
            $latestDevelopmentRun->graph_snapshot['nodes'][0]['id'] ?? null,
        );

        $graphEventIds = collect($latestDevelopmentRun->graph_snapshot['events'] ?? [])
            ->pluck('id')
            ->all();
        $graphActorIds = collect($latestDevelopmentRun->graph_snapshot['actors'] ?? [])
            ->pluck('id')
            ->all();

        $this->assertContains('SendEmail', $graphEventIds);
        $this->assertContains('EscalationRequested', $graphEventIds);
        $this->assertContains('ApprovalRouter', $graphActorIds);
        $this->assertContains('PublishDigest', $graphActorIds);
        $this->assertCount(3, $latestDevelopmentRun->logs);

        $activeConversation = $flow->conversations
            ->firstWhere('id', $flow->active_chat_conversation_id);

        $this->assertNotNull($activeConversation);
        $this->assertSame('Escalation loop polish', $activeConversation->title);
        $this->assertCount(4, $activeConversation->messages);
        $this->assertSame(
            'code_suggestion',
            $activeConversation->messages[1]->meta['kind'] ?? null,
        );

        $archivedConversation = $flow->conversations
            ->firstWhere('id', '!=', $flow->active_chat_conversation_id);

        $this->assertNotNull($archivedConversation);
        $this->assertSame('compact_summary', $archivedConversation->messages[1]->meta['kind'] ?? null);
    }

    public function test_showcase_seeder_replaces_previous_showcase_records_on_reseed(): void
    {
        User::factory()->admin()->withoutTwoFactor()->create([
            'name' => 'Admin',
            'email' => 'admin@kawaflow.localhost',
            'password' => bcrypt('12345678'),
        ]);

        $this->seed(\Database\Seeders\AdminShowcaseFlowSeeder::class);
        $this->seed(\Database\Seeders\AdminShowcaseFlowSeeder::class);

        $flow = Flow::query()->where('slug', 'admin-showcase-flow')->first();

        $this->assertNotNull($flow);
        $this->assertSame(1, Flow::query()->where('slug', 'admin-showcase-flow')->count());
        $this->assertSame(3, $flow->runs()->count());
        $this->assertSame(2, $flow->conversations()->count());
    }
}
