<?php

namespace App\Console\Commands;

use App\Jobs\ProcessFlowManager;
use Illuminate\Console\Command;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class FlowManagerListen extends Command
{
    protected $signature = 'flow-manager:listen';

    protected $description = 'Consume flow-manager RabbitMQ events and enqueue processing jobs';

    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function handle(): int
    {
        $this->info('Starting flow-manager event consumer...');

        try {
            $this->connect();
            $this->consume();
        } catch (Throwable $exception) {
            $this->error('Consumer failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->close();
        }

        return self::SUCCESS;
    }

    private function consume(): void
    {
        if (! $this->channel) {
            throw new \RuntimeException('AMQP channel not initialized');
        }

        $queue = config('services.flow_manager.event_queue', 'flow-manager.ui.events');
        $this->channel->basic_qos(0, 10, false);

        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message): void {
                $this->handleMessage($message);
            }
        );

        while ($this->channel->is_consuming()) {
            try {
                $this->channel->wait(null, false, max(1, $this->timeoutSeconds()));
            } catch (AMQPTimeoutException) {
                // keep looping to allow graceful stop
            }
        }
    }

    private function handleMessage(AMQPMessage $message): void
    {
        $routingKey = $message->getRoutingKey() ?? '';
        $eventName = $routingKey ? ltrim(str_replace('event.', '', $routingKey), '.') : 'event';
        $payload = json_decode($message->getBody(), true) ?: [];

        ProcessFlowManager::dispatchSync($eventName, $payload);
        $message->ack();
    }

    private function connect(): void
    {
        $config = $this->parseRabbitUrl(
            (string) config('services.flow_manager.rabbitmq_url', 'amqp://guest:guest@rabbitmq:5672/')
        );
        $exchange = config('services.flow_manager.event_exchange', 'flow-manager.events');
        $queue = config('services.flow_manager.event_queue', 'flow-manager.ui.events');

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
        $this->channel->exchange_declare($exchange, 'topic', false, true, false);
        $this->channel->queue_declare($queue, false, true, false, false);
        $this->channel->queue_bind($queue, $exchange, 'event.#');
    }

    private function close(): void
    {
        $this->channel?->close();
        $this->connection?->close();
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
        return (int) config('services.flow_manager.timeout', 8000) / 1000;
    }
}
