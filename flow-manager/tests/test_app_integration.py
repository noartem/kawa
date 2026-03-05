from unittest.mock import AsyncMock, Mock, patch

import pytest

from event_handler import EventHandler
from main import FlowManagerApp
from messaging import InMemoryMessaging


def test_flow_manager_app_uses_pluggable_messaging(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    assert isinstance(app.messaging, InMemoryMessaging)
    assert isinstance(app.event_handler, EventHandler)
    assert app.event_handler.messaging is app.messaging


@pytest.mark.asyncio
async def test_flow_manager_shutdown_is_idempotent(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    app._started = True
    app.container_manager.stop_monitoring = AsyncMock()
    app.socket_handler.close_all_connections = AsyncMock()
    app.messaging.close = AsyncMock()

    await app.shutdown()
    await app.shutdown()

    app.container_manager.stop_monitoring.assert_called_once()
    app.socket_handler.close_all_connections.assert_called_once()
    app.messaging.close.assert_called_once()


def test_should_publish_container_status_only_on_changes(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    assert app._should_publish_container_status(
        "container-1", "running", "healthy", True
    )
    assert not app._should_publish_container_status(
        "container-1", "running", "healthy", True
    )
    assert app._should_publish_container_status(
        "container-1", "stopped", "healthy", True
    )


def test_prune_status_cache_removes_stale_containers(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    app._should_publish_container_status("container-1", "running", "healthy", True)
    app._should_publish_container_status("container-2", "running", "healthy", True)

    app._prune_status_cache({"container-1"})

    assert "container-1" in app._last_published_container_status
    assert "container-2" not in app._last_published_container_status


@pytest.mark.asyncio
async def test_handle_runtime_socket_message_publishes_actor_message(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    app.messaging.publish_event = AsyncMock()

    await app._handle_runtime_socket_message(
        "container-1",
        {
            "type": "kawa_message",
            "message": "hello from actor",
        },
    )

    app.messaging.publish_event.assert_called_once_with(
        "actor_message",
        {
            "container_id": "container-1",
            "message": "hello from actor",
        },
    )


@pytest.mark.asyncio
async def test_drain_container_messages_reads_and_publishes(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    app.socket_handler.has_pending_connection = Mock(side_effect=[True, False])
    app.socket_handler.receive_message = AsyncMock(
        return_value={
            "type": "kawa_message",
            "message": "runtime log",
        }
    )
    app.messaging.publish_event = AsyncMock()

    await app._drain_container_messages("container-1")

    app.socket_handler.receive_message.assert_called_once_with(
        "container-1",
        timeout=1,
    )
    app.messaging.publish_event.assert_called_once_with(
        "actor_message",
        {
            "container_id": "container-1",
            "message": "runtime log",
        },
    )
