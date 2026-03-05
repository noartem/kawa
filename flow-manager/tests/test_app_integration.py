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
