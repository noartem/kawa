import asyncio
import pytest
from unittest.mock import AsyncMock, Mock
from datetime import datetime

from container_manager import ContainerManager
from messaging import InMemoryMessaging
from event_handler import EventHandler
from socket_communication_handler import SocketCommunicationHandler
from system_logger import SystemLogger
from user_activity_logger import UserActivityLogger
from models import ContainerInfo


def _make_handler():
    messaging = InMemoryMessaging(logger=Mock(spec=SystemLogger))
    container_manager = Mock(spec=ContainerManager)
    socket_handler = Mock(spec=SocketCommunicationHandler)
    logger = Mock(spec=SystemLogger)
    user_logger = Mock(spec=UserActivityLogger)

    handler = EventHandler(
        messaging=messaging,
        container_manager=container_manager,
        socket_handler=socket_handler,
        logger=logger,
        user_logger=user_logger,
    )
    return handler, messaging, container_manager, socket_handler, user_logger


def test_handler_initialization_registers_callbacks():
    handler, _, container_manager, _, _ = _make_handler()
    container_manager.register_status_change_callback.assert_called_once()
    container_manager.register_health_check_callback.assert_called_once()
    container_manager.register_crash_callback.assert_called_once()
    container_manager.register_resource_alert_callback.assert_called_once()


@pytest.mark.asyncio
async def test_handle_create_container_success():
    handler, messaging, container_manager, socket_handler, user_logger = _make_handler()
    message = Mock(reply_to="reply-queue", correlation_id="corr-1")

    sample_container = ContainerInfo(
        id="test-container-123",
        name="test-container",
        status="created",
        image="test-image:latest",
        created=datetime.now(),
        socket_path="/tmp/test-container-123.sock",
        ports={"8080": 8080},
        environment={"TEST_VAR": "test_value"},
    )
    container_manager.create_container = AsyncMock(return_value=sample_container)
    socket_handler.setup_socket = AsyncMock()
    user_logger.container_created = AsyncMock()

    payload = {
        "action": "create_container",
        "data": {
            "image": "test-image:latest",
            "name": "test-container",
            "environment": {"TEST_VAR": "test_value"},
            "volumes": {"/host/path": "/container/path"},
            "ports": {"8080": 8080},
        },
    }
    await handler._dispatch_command(payload, message=message)
    await asyncio.sleep(0)

    container_manager.create_container.assert_awaited()
    socket_handler.setup_socket.assert_awaited_with(
        sample_container.id, sample_container.name
    )
    user_logger.container_created.assert_awaited()

    assert messaging.published_responses
    response = messaging.published_responses[0]["payload"]
    assert response["ok"] is True
    assert response["data"]["container_id"] == sample_container.id


@pytest.mark.asyncio
async def test_handle_send_message_error():
    handler, messaging, _, socket_handler, _ = _make_handler()
    socket_handler.send_message = AsyncMock(side_effect=Exception("send failure"))
    message = Mock(reply_to="reply-queue", correlation_id="corr-1")

    payload = {
        "action": "send_message",
        "data": {"container_id": "cid", "message": {"hello": "world"}},
    }
    await handler._dispatch_command(payload, message=message)

    assert messaging.published_responses
    error_response = messaging.published_responses[0]["payload"]
    assert error_response["error"] is True
    assert error_response["error_type"] == "system_error"


@pytest.mark.asyncio
async def test_handle_get_container_graph_success():
    handler, messaging, container_manager, _, _ = _make_handler()
    message = Mock(reply_to="reply-queue", correlation_id="corr-1")
    container_manager.get_container_graph = AsyncMock(
        return_value={"actors": [{"id": "a1"}], "events": []}
    )

    payload = {
        "action": "get_container_graph",
        "data": {"container_id": "cid"},
    }

    await handler._dispatch_command(payload, message=message)

    container_manager.get_container_graph.assert_awaited_once_with("cid")
    assert messaging.published_responses
    response = messaging.published_responses[0]["payload"]
    assert response["ok"] is True
    assert response["data"] == {
        "container_id": "cid",
        "graph": {"actors": [{"id": "a1"}], "events": []},
    }


@pytest.mark.asyncio
async def test_dispatch_command_propagates_cancellation():
    handler, messaging, _, _, _ = _make_handler()
    message = Mock(reply_to="reply-queue", correlation_id="corr-1")

    handler.handlers["list_containers"] = AsyncMock(
        side_effect=asyncio.CancelledError()
    )

    with pytest.raises(asyncio.CancelledError):
        await handler._dispatch_command(
            {
                "action": "list_containers",
                "data": {},
            },
            message=message,
        )

    assert messaging.published_responses == []


@pytest.mark.asyncio
async def test_fire_and_forget_command_skips_response_queue_publish():
    handler, messaging, container_manager, socket_handler, user_logger = _make_handler()

    sample_container = ContainerInfo(
        id="test-container-123",
        name="test-container",
        status="created",
        image="test-image:latest",
        created=datetime.now(),
        socket_path="/tmp/test-container-123.sock",
        ports={},
        environment={},
    )
    container_manager.create_container = AsyncMock(return_value=sample_container)
    socket_handler.setup_socket = AsyncMock()
    user_logger.container_created = AsyncMock()

    await handler._dispatch_command(
        {
            "action": "create_container",
            "data": {
                "image": "test-image:latest",
                "name": "test-container",
            },
        },
        message=None,
    )
    await asyncio.sleep(0)

    assert messaging.published_responses == []


@pytest.mark.asyncio
async def test_status_change_callback_publishes_only_domain_event():
    handler, messaging, container_manager, _, user_logger = _make_handler()
    container = Mock()
    container.labels = {
        "kawaflow.flow_id": "7",
        "kawaflow.flow_run_id": "11",
        "kawaflow.graph_hash": "graph-hash",
    }
    container_manager.docker_client = Mock()
    container_manager.docker_client.containers = Mock()
    container_manager.docker_client.containers.get.return_value = container

    user_logger.user_activity = AsyncMock()

    await handler._on_status_change("cid", "running", "exited")

    assert len(messaging.published_events) == 1
    assert messaging.published_events[0]["event"] == "status_changed"
    assert messaging.published_events[0]["payload"] == {
        "container_id": "cid",
        "flow_id": 7,
        "flow_run_id": 11,
        "graph_hash": "graph-hash",
        "old_state": "running",
        "new_state": "exited",
        "timestamp": messaging.published_events[0]["payload"]["timestamp"],
    }
    user_logger.user_activity.assert_not_called()


@pytest.mark.asyncio
async def test_health_warning_callback_publishes_only_domain_event():
    handler, messaging, _, _, user_logger = _make_handler()

    user_logger.user_activity = AsyncMock()

    await handler._on_health_check_failure("cid", "unhealthy")

    assert len(messaging.published_events) == 1
    assert messaging.published_events[0]["event"] == "container_health_warning"
    user_logger.user_activity.assert_not_called()


@pytest.mark.asyncio
async def test_container_crash_callback_publishes_only_domain_event():
    handler, messaging, _, _, user_logger = _make_handler()

    user_logger.user_activity = AsyncMock()

    await handler._on_container_crash("cid", 137, {"reason": "oom"})

    assert len(messaging.published_events) == 1
    assert messaging.published_events[0]["event"] == "container_crashed"
    user_logger.user_activity.assert_not_called()


@pytest.mark.asyncio
async def test_resource_alert_callback_publishes_only_domain_event():
    handler, messaging, _, _, user_logger = _make_handler()

    user_logger.user_activity = AsyncMock()

    await handler._on_resource_alert(
        "cid",
        "cpu",
        0.91,
        0.80,
        {"cpu_percent": 91},
    )

    assert len(messaging.published_events) == 1
    assert messaging.published_events[0]["event"] == "resource_alert"
    user_logger.user_activity.assert_not_called()
