<?php

namespace Tests\Feature;

use App\Console\Commands\FlowManagerListen;
use App\Models\Flow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use ReflectionMethod;
use Tests\TestCase;

class FlowManagerListenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_processes_flow_manager_event_synchronously(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $flow = Flow::factory()->forUser($user)->createOne([
            'container_id' => null,
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => true,
            'status' => 'creating',
            'started_at' => now(),
            'code_snapshot' => $flow->code,
            'graph_snapshot' => [
                'nodes' => [],
                'edges' => [],
            ],
        ]);

        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getRoutingKey')
            ->once()
            ->andReturn('event.container_created');
        $message->shouldReceive('getBody')
            ->once()
            ->andReturn(json_encode([
                'flow_id' => $flow->id,
                'flow_run_id' => $run->id,
                'container_id' => 'container-id-1',
                'actors' => [],
                'events' => [],
            ], JSON_THROW_ON_ERROR));
        $message->shouldReceive('ack')
            ->once();

        $command = app(FlowManagerListen::class);
        $method = new ReflectionMethod($command, 'handleMessage');
        $method->setAccessible(true);
        $method->invoke($command, $message);

        $run->refresh();
        $flow->refresh();

        $this->assertSame('container-id-1', $run->container_id);
        $this->assertSame('created', $run->status);
        $this->assertSame('container-id-1', $flow->container_id);
    }
}
