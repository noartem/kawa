import json
import os
from typing import Any, Dict, Optional

import aio_pika

from messaging import CommandHandler, Messaging
from system_logger import SystemLogger


class RabbitMQMessaging(Messaging):
    def __init__(
        self,
        logger: SystemLogger,
        url: Optional[str] = None,
        command_queue: Optional[str] = None,
        response_queue: Optional[str] = None,
        event_exchange: Optional[str] = None,
        prefetch_count: int = 20,
    ):
        self.logger = logger
        self.url = url or os.getenv("RABBITMQ_URL", "amqp://guest:guest@rabbitmq:5672/")
        self.command_queue_name = command_queue or os.getenv(
            "FLOW_MANAGER_COMMAND_QUEUE", "flow-manager.commands"
        )
        self.response_queue_name = response_queue or os.getenv(
            "FLOW_MANAGER_RESPONSE_QUEUE", "flow-manager.responses"
        )
        self.event_exchange_name = event_exchange or os.getenv(
            "FLOW_MANAGER_EVENT_EXCHANGE", "flow-manager.events"
        )
        self.prefetch_count = prefetch_count

        self.connection: Optional[aio_pika.RobustConnection] = None
        self.channel: Optional[aio_pika.abc.AbstractChannel] = None
        self.event_exchange: Optional[aio_pika.abc.AbstractExchange] = None
        self.command_queue: Optional[aio_pika.abc.AbstractQueue] = None
        self.response_queue: Optional[aio_pika.abc.AbstractQueue] = None
        self._consumer_tag: Optional[str] = None

    async def connect(self) -> None:
        """Establish connection and declare exchanges/queues."""
        self.logger.debug(
            "Connecting to RabbitMQ",
            {
                "url": self.url,
                "command_queue": self.command_queue_name,
                "response_queue": self.response_queue_name,
                "event_exchange": self.event_exchange_name,
            },
        )

        self.connection = await aio_pika.connect_robust(self.url)
        self.channel = await self.connection.channel()
        await self.channel.set_qos(prefetch_count=self.prefetch_count)

        self.event_exchange = await self.channel.declare_exchange(
            self.event_exchange_name,
            aio_pika.ExchangeType.TOPIC,
            durable=True,
        )

        self.command_queue = await self.channel.declare_queue(
            self.command_queue_name, durable=True
        )
        await self.command_queue.bind(self.event_exchange, routing_key="command.*")

        self.response_queue = await self.channel.declare_queue(
            self.response_queue_name, durable=True
        )

        self.logger.debug(
            "RabbitMQ connection established",
            {
                "event_exchange": self.event_exchange_name,
                "command_queue": self.command_queue_name,
                "response_queue": self.response_queue_name,
            },
        )

    async def consume_commands(self, handler: CommandHandler) -> None:
        """
        Start consuming command messages.

        Args:
            handler: Coroutine handling (payload, message)
        """

        async def _on_message(message: aio_pika.IncomingMessage) -> None:
            async with message.process(ignore_processed=True):
                payload = self._deserialize_message(message)
                if payload is None:
                    return
                await handler(payload, message)

        if not self.command_queue:
            raise RuntimeError("Command queue is not initialized")

        self._consumer_tag = await self.command_queue.consume(_on_message)
        self.logger.debug(
            "Command consumer started", {"consumer_tag": self._consumer_tag}
        )

    async def stop_consuming(self) -> None:
        """Stop consuming commands."""
        if self.command_queue and self._consumer_tag:
            await self.command_queue.cancel(self._consumer_tag)
            self.logger.debug(
                "Command consumer stopped", {"consumer_tag": self._consumer_tag}
            )
            self._consumer_tag = None

    async def publish_response(
        self,
        action: str,
        payload: Dict[str, Any],
        reply_to: Optional[str] = None,
        correlation_id: Optional[str] = None,
    ) -> None:
        """Publish a direct response to a reply queue."""
        target_queue = reply_to or self.response_queue_name
        if not self.channel or not target_queue:
            raise RuntimeError("Channel not initialized for publishing responses")

        message = aio_pika.Message(
            body=json.dumps(payload).encode("utf-8"),
            correlation_id=correlation_id,
            content_type="application/json",
        )
        await self.channel.default_exchange.publish(message, routing_key=target_queue)
        self.logger.debug(
            "Published response",
            {
                "action": action,
                "reply_to": target_queue,
                "correlation_id": correlation_id,
            },
        )

    async def publish_event(
        self,
        event_name: str,
        payload: Dict[str, Any],
        routing_key: Optional[str] = None,
        correlation_id: Optional[str] = None,
    ) -> None:
        """Publish an event to the topic exchange."""
        if not self.event_exchange:
            raise RuntimeError("Event exchange not initialized")

        message = aio_pika.Message(
            body=json.dumps(payload).encode("utf-8"),
            correlation_id=correlation_id,
            content_type="application/json",
        )
        await self.event_exchange.publish(
            message,
            routing_key=routing_key or f"event.{event_name}",
            mandatory=False,
        )
        self.logger.debug(
            "Published event",
            {
                "event": event_name,
                "routing_key": routing_key or f"event.{event_name}",
            },
        )

    async def close(self) -> None:
        """Close channel and connection."""
        await self.stop_consuming()
        if self.channel and not self.channel.is_closed:
            await self.channel.close()
        if self.connection and not self.connection.is_closed:
            await self.connection.close()
        self.logger.debug("RabbitMQ connection closed", {})

    def _deserialize_message(
        self, message: aio_pika.IncomingMessage
    ) -> Optional[Dict[str, Any]]:
        """Parse message JSON payload safely."""
        try:
            return json.loads(message.body.decode("utf-8"))
        except Exception as exc:
            self.logger.error(
                exc,
                {
                    "operation": "deserialize_message",
                    "payload": message.body.decode("utf-8", errors="ignore"),
                },
            )
            return None
