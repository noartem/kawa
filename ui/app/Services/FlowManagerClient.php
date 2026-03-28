<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Throwable;

class FlowManagerClient
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function __construct(
        private readonly ?string $rabbitUrl = null,
        private readonly ?string $commandQueue = null,
        private readonly ?string $responseQueue = null,
        private readonly ?string $eventExchange = null,
        private readonly ?int $timeoutMs = null,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public function health(): array
    {
        try {
            $this->connect();

            return ['ok' => true, 'message' => 'RabbitMQ connection established'];
        } catch (Throwable $exception) {
            Log::error('flow-manager health failed', [
                'exception' => $exception->getMessage(),
            ]);

            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    public function createContainer(array $payload): array
    {
        return $this->run('create_container', $payload);
    }

    public function startContainer(string $containerId): array
    {
        return $this->run('start_container', ['container_id' => $containerId]);
    }

    public function stopContainer(string $containerId): array
    {
        return $this->run('stop_container', ['container_id' => $containerId]);
    }

    public function deleteContainer(string $containerId): array
    {
        return $this->run('delete_container', ['container_id' => $containerId]);
    }

    public function status(string $containerId): array
    {
        return $this->run('get_container_status', ['container_id' => $containerId]);
    }

    public function listContainers(): array
    {
        return $this->run('list_containers', waitForResponse: true);
    }

    public function generateLock(array $payload): array
    {
        return $this->run('generate_lock', $payload);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function sendMessage(string $containerId, array $message): array
    {
        return $this->run('send_message', [
            'container_id' => $containerId,
            'message' => $message,
        ], waitForResponse: true, responseTimeoutMs: 3000);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function containerGraph(string $containerId): ?array
    {
        $response = $this->run(
            'get_container_graph',
            ['container_id' => $containerId],
            waitForResponse: true,
        );

        if (($response['ok'] ?? false) !== true) {
            Log::debug('flow-manager graph request failed', [
                'container_id' => $containerId,
                'message' => $response['message'] ?? 'Unknown error',
            ]);

            return null;
        }

        $payload = $response['data'] ?? null;
        if (! is_array($payload)) {
            return null;
        }

        $graph = $payload['graph'] ?? null;

        return is_array($graph) ? $graph : null;
    }

    protected function run(
        string $action,
        array $payload = [],
        bool $waitForResponse = false,
        ?int $responseTimeoutMs = null,
    ): array {
        $replyQueue = null;

        try {
            $this->connect();

            $correlationId = (string) Str::uuid();
            if ($waitForResponse) {
                $replyQueue = $this->declareReplyQueue();
            }

            $messagePayload = [
                'action' => $action,
                'data' => $payload,
                'correlation_id' => $correlationId,
            ];

            if ($replyQueue !== null) {
                $messagePayload['reply_to'] = $replyQueue;
            }

            $properties = [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'correlation_id' => $correlationId,
            ];

            if ($replyQueue !== null) {
                $properties['reply_to'] = $replyQueue;
            }

            $message = new AMQPMessage(
                json_encode($messagePayload, JSON_THROW_ON_ERROR),
                $properties,
            );

            $this->channel?->basic_publish(
                $message,
                $this->eventExchangeName(),
                'command.'.$action,
            );

            if (! $waitForResponse) {
                return [
                    'ok' => true,
                    'message' => 'Command published to RabbitMQ',
                    'correlation_id' => $correlationId,
                ];
            }

            $responsePayload = $this->awaitResponse(
                $replyQueue,
                $correlationId,
                $action,
                $responseTimeoutMs,
            );

            return $this->normalizeResponse($responsePayload, $correlationId);
        } catch (Throwable $exception) {
            Log::error('flow-manager command failed', [
                'action' => $action,
                'payload' => $payload,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        } finally {
            if ($replyQueue !== null) {
                $this->deleteReplyQueue($replyQueue);
            }
        }
    }

    private function declareReplyQueue(): string
    {
        if (! $this->channel) {
            throw new RuntimeException('AMQP channel is not initialized');
        }

        [$queueName] = $this->channel->queue_declare(
            '',
            false,
            false,
            true,
            true,
        );

        return (string) $queueName;
    }

    private function awaitResponse(
        string $replyQueue,
        string $correlationId,
        string $action,
        ?int $responseTimeoutMs = null,
    ): array {
        if (! $this->channel) {
            throw new RuntimeException('AMQP channel is not initialized');
        }

        $deadline = microtime(true) + $this->responseTimeoutSeconds($responseTimeoutMs);

        while (microtime(true) < $deadline) {
            $response = $this->channel->basic_get($replyQueue, true);

            if (! $response) {
                usleep(50000);

                continue;
            }

            $messageCorrelationId = (string) ($response->get('correlation_id') ?? '');
            if ($messageCorrelationId !== '' && $messageCorrelationId !== $correlationId) {
                continue;
            }

            $decoded = json_decode($response->getBody(), true);
            if (! is_array($decoded)) {
                throw new RuntimeException('Invalid response from flow-manager');
            }

            return $decoded;
        }

        throw new RuntimeException("Flow-manager response timeout for action '{$action}'");
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function normalizeResponse(array $response, string $correlationId): array
    {
        if (($response['error'] ?? false) === true || ($response['ok'] ?? true) === false) {
            $message = $response['message'] ?? 'Flow-manager command failed';

            return [
                'ok' => false,
                'message' => is_string($message) ? $message : 'Flow-manager command failed',
                'error_type' => $response['error_type'] ?? null,
                'details' => $response['details'] ?? [],
                'correlation_id' => $correlationId,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Command processed by flow-manager',
            'correlation_id' => $correlationId,
            'data' => $response['data'] ?? null,
        ];
    }

    private function deleteReplyQueue(string $replyQueue): void
    {
        if (! $this->channel) {
            return;
        }

        try {
            $this->channel->queue_delete($replyQueue);
        } catch (Throwable $exception) {
            Log::debug('failed to delete temporary flow-manager queue', [
                'queue' => $replyQueue,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function connect(): void
    {
        if ($this->connection?->isConnected() && $this->channel !== null) {
            return;
        }

        $config = $this->parseRabbitUrl(
            $this->rabbitUrl ?? (string) config('services.flow_manager.rabbitmq_url')
        );

        $this->connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost'],
            connection_timeout: $this->timeoutSeconds(),
            read_write_timeout: $this->timeoutSeconds(),
            heartbeat: 0.0,
            channel_rpc_timeout: $this->timeoutSeconds(),
        );

        $this->channel = $this->connection->channel();
        $this->setupTopology();
    }

    private function setupTopology(): void
    {
        if (! $this->channel) {
            return;
        }

        $exchange = $this->eventExchangeName();
        $commandQueue = $this->commandQueueName();
        $responseQueue = $this->responseQueueName();

        $this->channel->exchange_declare($exchange, 'topic', false, true, false);
        $this->channel->queue_declare($commandQueue, false, true, false, false);
        $this->channel->queue_bind($commandQueue, $exchange, 'command.*');

        if (is_string($responseQueue) && $responseQueue !== '') {
            $this->channel->queue_declare($responseQueue, false, true, false, false);
        }
    }

    private function parseRabbitUrl(string $url): array
    {
        $parts = parse_url($url) ?: [];

        return [
            'host' => $parts['host'] ?? 'rabbitmq',
            'port' => (int) ($parts['port'] ?? 5672),
            'user' => $parts['user'] ?? 'guest',
            'password' => $parts['pass'] ?? 'guest',
            'vhost' => isset($parts['path']) && $parts['path'] !== '/'
                ? ltrim($parts['path'], '/')
                : '/',
        ];
    }

    private function timeoutSeconds(): float
    {
        return ($this->timeoutMs ?? (int) config('services.flow_manager.timeout', 8000)) / 1000;
    }

    private function responseTimeoutSeconds(?int $overrideMs = null): float
    {
        $configured = $overrideMs ?? (int) config('services.flow_manager.response_timeout', 45000);

        return max($configured, 1000) / 1000;
    }

    private function eventExchangeName(): string
    {
        return $this->eventExchange ?? (string) config('services.flow_manager.event_exchange', 'flow-manager.events');
    }

    private function commandQueueName(): string
    {
        return $this->commandQueue ?? (string) config('services.flow_manager.command_queue', 'flow-manager.commands');
    }

    private function responseQueueName(): ?string
    {
        return $this->responseQueue ? (string) $this->responseQueue : null;
    }

    private function close(): void
    {
        $this->channel?->close();
        $this->connection?->close();
    }
}
