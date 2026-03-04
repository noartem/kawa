<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowGraphDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cron_flow_is_created_with_empty_graph(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $this->actingAs($user)->post(route('flows.store'), [
            'name' => 'Morning flow',
            'description' => 'Daily cron scenario',
            'template' => 'cron',
        ])->assertRedirect();

        $flow = Flow::query()
            ->where('user_id', $user->id)
            ->where('name', 'Morning flow')
            ->first();

        $this->assertNotNull($flow);
        $this->assertSame(['nodes' => [], 'edges' => []], $flow->graph);
    }

    public function test_show_does_not_backfill_graph_when_it_is_empty(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'name' => 'Legacy cron flow',
            'code' => <<<'PY'
from kawa import actor, Context
from kawa.cron import CronEvent


@actor(receivs=CronEvent.by("0 8 * * *"))
def MorningActor(ctx: Context, event):
    print("Good morning!")
PY,
            'graph' => ['nodes' => [], 'edges' => []],
        ]);

        $this->actingAs($user)
            ->get(route('flows.show', $flow))
            ->assertOk();

        $flow->refresh();

        $this->assertSame(['nodes' => [], 'edges' => []], $flow->graph);
    }
}
