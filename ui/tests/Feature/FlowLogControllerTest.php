<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowLog;
use App\Models\FlowRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_endpoint_orders_same_second_entries_by_id(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne();
        $run = FlowRun::factory()->forFlow($flow)->createOne();
        $timestamp = now()->startOfSecond();

        FlowLog::factory()->forRun($run)->createOne([
            'message' => 'Actor invoked by webhook',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        FlowLog::factory()->forRun($run)->createOne([
            'message' => 'Actor dispatched message',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->actingAs($user)->getJson(route('flows.logs', $flow));

        $response->assertOk();
        $response->assertJsonPath('data.data.0.message', 'Actor dispatched message');
        $response->assertJsonPath('data.data.1.message', 'Actor invoked by webhook');
    }
}
