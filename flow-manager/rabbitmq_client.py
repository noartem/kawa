import json
import os
from typing import Any, Dict, Optional

import aio_pika
from aiormq.exceptions import ChannelInvalidStateError

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
        prefetch_count: int = 1,
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

        self.connection: Optional[Any] = None
        self.command_channel: Optional[Any] = None
        self.publish_channel: Optional[Any] = None
        self.event_exchange: Optional[Any] = None
        self.command_queue: Optional[Any] = None
        self.response_queue: Optional[Any] = None
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

        if self.connection is None or self.connection.is_closed:
            self.connection = await aio_pika.connect_robust(self.url)

        await self._setup_command_channel()
        await self._setup_publish_channel()

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

        async def _on_message(message: Any) -> None:
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
        await self._ensure_publish_channel()

        if not self.publish_channel or not target_queue:
            raise RuntimeError("Channel not initialized for publishing responses")

        message = aio_pika.Message(
            body=json.dumps(payload).encode("utf-8"),
            correlation_id=correlation_id,
            content_type="application/json",
        )
        await self.publish_channel.default_exchange.publish(
            message, routing_key=target_queue
        )
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
        await self._ensure_publish_channel()

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

        if self.command_channel and not self.command_channel.is_closed:
            await self.command_channel.close()
        if (
            self.publish_channel
            and not self.publish_channel.is_closed
            and self.publish_channel is not self.command_channel
        ):
            await self.publish_channel.close()
        if self.connection and not self.connection.is_closed:
            await self.connection.close()

        self.command_channel = None
        self.publish_channel = None
        self.command_queue = None
        self.response_queue = None
        self.event_exchange = None
        self.connection = None
        self._consumer_tag = None
        self.logger.debug("RabbitMQ connection closed", {})

    async def _setup_command_channel(self) -> None:
        if not self.connection or self.connection.is_closed:
            raise RuntimeError("RabbitMQ connection is not initialized")

        self.command_channel = await self.connection.channel()
        await self.command_channel.set_qos(prefetch_count=self.prefetch_count)

        command_exchange = await self.command_channel.declare_exchange(
            self.event_exchange_name,
            aio_pika.ExchangeType.TOPIC,
            durable=True,
        )
        self.command_queue = await self.command_channel.declare_queue(
            self.command_queue_name, durable=True
        )
        await self.command_queue.bind(command_exchange, routing_key="command.*")

    async def _setup_publish_channel(self) -> None:
        if not self.connection or self.connection.is_closed:
            raise RuntimeError("RabbitMQ connection is not initialized")

        self.publish_channel = await self.connection.channel()
        self.event_exchange = await self.publish_channel.declare_exchange(
            self.event_exchange_name,
            aio_pika.ExchangeType.TOPIC,
            durable=True,
        )
        self.response_queue = await self.publish_channel.declare_queue(
            self.response_queue_name, durable=True
        )

    async def _ensure_publish_channel(self) -> None:
        publish_channel_is_closed = (
            self.publish_channel is None or self.publish_channel.is_closed
        )
        event_exchange_is_closed = self.event_exchange is None

        if not publish_channel_is_closed and not event_exchange_is_closed:
            return

        if self.connection is None or self.connection.is_closed:
            self.connection = await aio_pika.connect_robust(self.url)

        try:
            await self._setup_publish_channel()
        except ChannelInvalidStateError as exc:
            self.logger.error(
                exc,
                {
                    "operation": "setup_publish_channel",
                    "event_exchange": self.event_exchange_name,
                },
            )
            raise

    def _deserialize_message(self, message: Any) -> Optional[Dict[str, Any]]:
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
