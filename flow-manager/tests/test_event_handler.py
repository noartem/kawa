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
    await handler._dispatch_command(payload, message=None)

    container_manager.create_container.assert_awaited()
    socket_handler.setup_socket.assert_awaited_with(
        sample_container.name, sample_container.name
    )
    socket_handler.link_socket_key.assert_called_once_with(
        sample_container.name, sample_container.id
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

    payload = {
        "action": "send_message",
        "data": {"container_id": "cid", "message": {"hello": "world"}},
    }
    await handler._dispatch_command(payload, message=None)

    assert messaging.published_responses
    error_response = messaging.published_responses[0]["payload"]
    assert error_response["error"] is True
    assert error_response["error_type"] == "system_error"


@pytest.mark.asyncio
async def test_handle_get_container_graph_success():
    handler, messaging, container_manager, _, _ = _make_handler()
    container_manager.get_container_graph = AsyncMock(
        return_value={"actors": [{"id": "a1"}], "events": []}
    )

    payload = {
        "action": "get_container_graph",
        "data": {"container_id": "cid"},
    }

    await handler._dispatch_command(payload, message=None)

    container_manager.get_container_graph.assert_awaited_once_with("cid")
    assert messaging.published_responses
    response = messaging.published_responses[0]["payload"]
    assert response["ok"] is True
    assert response["data"] == {
        "container_id": "cid",
        "graph": {"actors": [{"id": "a1"}], "events": []},
    }
