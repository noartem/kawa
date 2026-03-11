<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowHistory;
use App\Models\FlowLog;
use App\Models\FlowRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FlowDeploymentsPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_payload_includes_deployment_details_with_snapshots_and_logs(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'code' => 'print("latest")',
            'graph' => ['nodes' => [], 'edges' => []],
        ]);

        FlowHistory::query()->create([
            'flow_id' => $flow->id,
            'code' => 'print("legacy")',
            'diff' => null,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $oldRun = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'production',
            'status' => 'completed',
            'active' => false,
            'code_snapshot' => null,
            'graph_snapshot' => null,
            'events' => [
                [
                    'id' => 'event.old',
                    'source_line' => 7,
                    'source_kind' => 'import',
                    'source_module' => 'events.old',
                ],
                [
                    'id' => 'event.done',
                    'source_line' => 11,
                    'source_kind' => 'main',
                ],
            ],
            'actors' => [[
                'id' => 'actor.old',
                'receives' => ['event.old'],
                'sends' => ['event.done'],
                'source_line' => 16,
                'source_kind' => 'import',
                'source_module' => 'actors.old',
            ]],
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2)->addMinutes(5),
        ]);

        $newRun = FlowRun::factory()->forFlow($flow)->createOne([
            'type' => 'development',
            'status' => 'running',
            'active' => true,
            'code_snapshot' => 'print("snapshot")',
            'graph_snapshot' => [
                'nodes' => [['id' => 'node.snapshot', 'type' => 'event', 'label' => 'node.snapshot']],
                'edges' => [],
            ],
            'started_at' => now()->subMinutes(10),
        ]);

        FlowLog::factory()->forRun($oldRun)->createOne([
            'message' => 'Old deployment log',
        ]);

        FlowLog::factory()->count(55)->forRun($newRun)->create();

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->has('deployments', 2)
            ->where('productionLogsCount', 0)
            ->where('deployments.0.id', $newRun->id)
            ->where('deployments.0.code', 'print("snapshot")')
            ->where('deployments.0.graph.nodes.0.id', 'node.snapshot')
            ->has('deployments.0.logs', 50)
            ->where('deployments.1.id', $oldRun->id)
            ->where('deployments.1.code', 'print("legacy")')
            ->where('deployments.1.graph.nodes.0.id', 'event.old')
            ->where('deployments.1.graph.nodes.0.source_line', 7)
            ->where('deployments.1.graph.nodes.0.source_kind', 'import')
            ->where('deployments.1.graph.nodes.0.source_module', 'events.old')
            ->where('deployments.1.graph.nodes.1.id', 'event.done')
            ->where('deployments.1.graph.nodes.1.source_line', 11)
            ->where('deployments.1.graph.nodes.1.source_kind', 'main')
            ->where('deployments.1.graph.nodes.2.id', 'actor.old')
            ->where('deployments.1.graph.nodes.2.source_line', 16)
            ->where('deployments.1.graph.nodes.2.source_kind', 'import')
            ->where('deployments.1.graph.nodes.2.source_module', 'actors.old')
            ->where('deployments.1.logs.0.message', 'Old deployment log')
            ->missing('productionLogs')
            ->missing('productionRuns')
            ->missing('developmentRuns')
            ->missing('viewMode')
            ->missing('requiresDeletePassword')
        );
    }

    public function test_editor_payload_contains_only_five_latest_deployments(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne();

        $runs = FlowRun::factory()->count(7)->forFlow($flow)->sequence(
            fn ($sequence) => [
                'created_at' => now()->subMinutes(7 - $sequence->index),
                'updated_at' => now()->subMinutes(7 - $sequence->index),
            ],
        )->create();

        $response = $this->actingAs($user)->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('flows/Editor')
            ->has('deployments', 5)
            ->where('deployments.0.id', $runs[6]->id)
            ->where('deployments.4.id', $runs[2]->id)
        );
    }
}
