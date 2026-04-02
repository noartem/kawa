from unittest.mock import AsyncMock, Mock, call, patch

import pytest

from rabbitmq_client import RabbitMQMessaging
from system_logger import SystemLogger


def _make_channel() -> Mock:
    channel = Mock()
    channel.is_closed = False
    channel.set_qos = AsyncMock()
    channel.declare_exchange = AsyncMock()
    channel.declare_queue = AsyncMock()
    channel.close = AsyncMock()
    channel.default_exchange = Mock()
    channel.default_exchange.publish = AsyncMock()
    return channel


@pytest.mark.asyncio
async def test_connect_uses_separate_command_and_publish_channels() -> None:
    logger = Mock(spec=SystemLogger)
    messaging = RabbitMQMessaging(logger=logger)

    connection = Mock()
    connection.is_closed = False

    command_channel = _make_channel()
    publish_channel = _make_channel()
    command_exchange = Mock()
    publish_exchange = Mock()
    command_queue = Mock()
    command_queue.bind = AsyncMock()
    response_queue = Mock()

    connection.channel = AsyncMock(side_effect=[command_channel, publish_channel])
    command_channel.declare_exchange.return_value = command_exchange
    command_channel.declare_queue.return_value = command_queue
    publish_channel.declare_exchange.return_value = publish_exchange
    publish_channel.declare_queue.return_value = response_queue

    with patch(
        "rabbitmq_client.aio_pika.connect_robust",
        AsyncMock(return_value=connection),
    ):
        await messaging.connect()

    connection.channel.assert_has_awaits([call(), call()])
    command_channel.set_qos.assert_awaited_once_with(prefetch_count=1)
    command_queue.bind.assert_awaited_once_with(
        command_exchange, routing_key="command.*"
    )
    assert messaging.command_channel is command_channel
    assert messaging.publish_channel is publish_channel
    assert messaging.command_queue is command_queue
    assert messaging.response_queue is response_queue
    assert messaging.event_exchange is publish_exchange


@pytest.mark.asyncio
async def test_publish_event_recreates_closed_publish_channel() -> None:
    logger = Mock(spec=SystemLogger)
    messaging = RabbitMQMessaging(logger=logger)

    connection = Mock()
    connection.is_closed = False
    messaging.connection = connection

    stale_publish_channel = _make_channel()
    stale_publish_channel.is_closed = True
    messaging.publish_channel = stale_publish_channel
    messaging.event_exchange = None

    fresh_publish_channel = _make_channel()
    fresh_exchange = Mock()
    fresh_exchange.publish = AsyncMock()
    fresh_response_queue = Mock()
    fresh_publish_channel.declare_exchange.return_value = fresh_exchange
    fresh_publish_channel.declare_queue.return_value = fresh_response_queue
    connection.channel = AsyncMock(return_value=fresh_publish_channel)

    await messaging.publish_event("runtime_graph_updated", {"container_id": "cid"})

    connection.channel.assert_awaited_once()
    fresh_exchange.publish.assert_awaited_once()
    assert messaging.publish_channel is fresh_publish_channel
    assert messaging.event_exchange is fresh_exchange
    assert messaging.response_queue is fresh_response_queue
