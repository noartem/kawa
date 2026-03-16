<?php

namespace Tests\Feature;

use App\Broadcasting\SingleLineLogBroadcaster;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class SingleLineLogBroadcasterTest extends TestCase
{
    public function test_broadcast_logs_payload_on_a_single_line(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->withArgs(function (string $message): bool {
                return str_contains($message, 'Broadcasting [flow-manager.status_changed]')
                    && str_contains($message, 'with payload: {"flow_id":4,"event":"status_changed"}')
                    && ! str_contains($message, "\n");
            });

        $broadcaster = new SingleLineLogBroadcaster($logger);

        $broadcaster->broadcast(
            ['flows'],
            'flow-manager.status_changed',
            ['flow_id' => 4, 'event' => 'status_changed'],
        );
    }
}
