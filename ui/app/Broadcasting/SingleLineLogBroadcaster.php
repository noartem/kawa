<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Psr\Log\LoggerInterface;

class SingleLineLogBroadcaster extends Broadcaster
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function auth($request): void
    {
        //
    }

    public function validAuthenticationResponse($request, $result): void
    {
        //
    }

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $formattedChannels = implode(', ', $this->formatChannels($channels));
        $formattedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->logger->info(
            'Broadcasting ['.$event.'] on channels ['.$formattedChannels.'] with payload: '.$formattedPayload,
        );
    }
}
