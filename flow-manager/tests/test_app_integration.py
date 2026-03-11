import asyncio
from unittest.mock import AsyncMock, Mock, call, patch

import pytest

from event_handler import EventHandler
from main import FlowManagerApp
from messaging import InMemoryMessaging
from socket_communication_handler import SocketTimeoutError


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
    active = Mock(
        id="active-flow",
        status="running",
        environment={"FLOW_RUN_ID": "42", "FLOW_TIMEZONE": "UTC"},
    )
    non_flow = Mock(id="non-flow", status="running", environment={})
    stopped_flow = Mock(
        id="stopped-flow",
        status="stopped",
        environment={"FLOW_RUN_ID": "99", "FLOW_TIMEZONE": "UTC"},
    )
    app.container_manager.list_containers = AsyncMock(
        return_value=[active, non_flow, stopped_flow]
    )
    app.container_manager.stop_container = AsyncMock()
    app.container_manager.stop_monitoring = AsyncMock()
    app.socket_handler.close_all_connections = AsyncMock()
    app.messaging.close = AsyncMock()

    await app.shutdown()
    await app.shutdown()

    app.container_manager.stop_monitoring.assert_called_once()
    app.container_manager.list_containers.assert_called_once()
    app.container_manager.stop_container.assert_called_once_with("active-flow")
    app.socket_handler.close_all_connections.assert_called_once()
    app.messaging.close.assert_called_once()


@pytest.mark.asyncio
async def test_flow_manager_shutdown_continues_if_container_stop_fails(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    app._started = True
    active = Mock(
        id="active-flow",
        status="running",
        environment={"FLOW_RUN_ID": "42", "FLOW_TIMEZONE": "UTC"},
    )
    app.container_manager.list_containers = AsyncMock(return_value=[active])
    app.container_manager.stop_container = AsyncMock(side_effect=RuntimeError("boom"))
    app.container_manager.stop_monitoring = AsyncMock()
    app.socket_handler.close_all_connections = AsyncMock()
    app.messaging.close = AsyncMock()

    await app.shutdown()

    app.container_manager.stop_monitoring.assert_called_once()
    app.container_manager.stop_container.assert_called_once_with("active-flow")
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
async def test_handle_runtime_socket_message_publishes_runtime_events(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    app.messaging.publish_event = AsyncMock()

    await app._handle_runtime_socket_message(
        "container-1",
        {
            "type": "runtime_events",
            "events": [
                {
                    "seq": 1,
                    "kind": "actor_invoked",
                    "actor": "CronStarter",
                    "event": "CronEvent",
                },
                {
                    "seq": 2,
                    "kind": "event_dispatched",
                    "actor": "CronStarter",
                    "event": "TickEvent",
                },
            ],
        },
    )

    app.messaging.publish_event.assert_has_awaits(
        [
            call(
                "flow_runtime_event",
                {
                    "container_id": "container-1",
                    "seq": 1,
                    "kind": "actor_invoked",
                    "actor": "CronStarter",
                    "event": "CronEvent",
                },
            ),
            call(
                "flow_runtime_event",
                {
                    "container_id": "container-1",
                    "seq": 2,
                    "kind": "event_dispatched",
                    "actor": "CronStarter",
                    "event": "TickEvent",
                },
            ),
        ]
    )


@pytest.mark.asyncio
async def test_drain_container_messages_reads_and_publishes_runtime_events(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    app.socket_handler.has_pending_connection = Mock(side_effect=[True, False])
    app.socket_handler.receive_message = AsyncMock(
        return_value={
            "type": "runtime_events",
            "events": [
                {
                    "seq": 10,
                    "kind": "event_dispatched",
                    "actor": "Worker",
                    "event": "Message",
                }
            ],
        }
    )
    app.messaging.publish_event = AsyncMock()

    await app._drain_container_messages("container-1")

    app.socket_handler.receive_message.assert_called_once_with(
        "container-1",
        timeout=1,
    )
    app.messaging.publish_event.assert_called_once_with(
        "flow_runtime_event",
        {
            "container_id": "container-1",
            "seq": 10,
            "kind": "event_dispatched",
            "actor": "Worker",
            "event": "Message",
        },
    )


@pytest.mark.asyncio
async def test_dispatch_cron_ticks_targets_only_active_flow_deployments(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    active = Mock(
        id="active-container",
        status="running",
        environment={"FLOW_RUN_ID": "42", "FLOW_TIMEZONE": "Europe/Berlin"},
    )
    active.name = "flow-2-run-1"
    non_flow = Mock(id="non-flow", status="running", environment={})
    stopped = Mock(
        id="stopped-container",
        status="stopped",
        environment={"FLOW_RUN_ID": "42", "FLOW_TIMEZONE": "UTC"},
    )

    app.container_manager.list_containers = AsyncMock(
        return_value=[active, non_flow, stopped]
    )
    app.socket_handler.is_socket_connected = Mock(return_value=False)
    app.socket_handler.setup_socket = AsyncMock()
    app.socket_handler.send_message = AsyncMock()
    app.socket_handler.receive_message = AsyncMock(
        return_value={
            "type": "runtime_ack",
            "command": "cron_tick",
            "ok": True,
        }
    )

    await app._dispatch_cron_ticks()

    app.socket_handler.send_message.assert_called_once_with(
        "active-container",
        {
            "command": "cron_tick",
            "data": {"timezone": "Europe/Berlin"},
        },
    )
    app.socket_handler.receive_message.assert_called_once_with(
        "active-container",
        timeout=5,
    )
    app.socket_handler.setup_socket.assert_called_once_with(
        "active-container",
        "flow-2-run-1",
    )


@pytest.mark.asyncio
async def test_restore_runtime_sockets_for_active_flow_deployments(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    active = Mock(id="active-container")
    active.name = "flow-2-run-1"
    active.status = "running"
    active.environment = {"FLOW_RUN_ID": "101"}

    non_flow = Mock(id="non-flow")
    non_flow.name = "utility-container"
    non_flow.status = "running"
    non_flow.environment = {}

    app.container_manager.list_containers = AsyncMock(return_value=[active, non_flow])
    app.socket_handler.setup_socket = AsyncMock()

    await app._restore_runtime_sockets()

    app.socket_handler.setup_socket.assert_called_once_with(
        "active-container",
        "flow-2-run-1",
    )


@pytest.mark.asyncio
async def test_poll_runtime_events_targets_only_active_flow_deployments(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    active = Mock(
        id="active-container",
        status="running",
        environment={"FLOW_RUN_ID": "42", "FLOW_TIMEZONE": "Europe/Berlin"},
    )
    active.name = "flow-2-run-1"
    non_flow = Mock(id="non-flow", status="running", environment={})
    stopped = Mock(
        id="stopped-container",
        status="stopped",
        environment={"FLOW_RUN_ID": "42", "FLOW_TIMEZONE": "UTC"},
    )

    app.container_manager.list_containers = AsyncMock(
        return_value=[active, non_flow, stopped]
    )
    app.socket_handler.is_socket_connected = Mock(return_value=False)
    app.socket_handler.setup_socket = AsyncMock()
    app.socket_handler.send_message = AsyncMock()
    app.socket_handler.receive_message = AsyncMock(
        return_value={
            "type": "runtime_events",
            "events": [
                {
                    "seq": 1,
                    "kind": "actor_invoked",
                    "actor": "CronStarter",
                    "event": "CronEvent",
                }
            ],
        }
    )
    app.messaging.publish_event = AsyncMock()

    await app._poll_runtime_events()

    app.socket_handler.send_message.assert_called_once_with(
        "active-container",
        {"command": "pull_events"},
    )
    app.socket_handler.receive_message.assert_called_once_with(
        "active-container",
        timeout=3,
    )
    app.socket_handler.setup_socket.assert_called_once_with(
        "active-container",
        "flow-2-run-1",
    )
    app.messaging.publish_event.assert_called_once_with(
        "flow_runtime_event",
        {
            "container_id": "active-container",
            "seq": 1,
            "kind": "actor_invoked",
            "actor": "CronStarter",
            "event": "CronEvent",
        },
    )


@pytest.mark.asyncio
async def test_poll_runtime_events_applies_backoff_after_repeated_timeouts(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    active = Mock(
        id="active-container",
        status="running",
        environment={"FLOW_RUN_ID": "42", "FLOW_TIMEZONE": "Europe/Berlin"},
    )
    active.name = "flow-2-run-1"

    app.container_manager.list_containers = AsyncMock(return_value=[active])
    app.socket_handler.is_socket_connected = Mock(return_value=True)
    app.socket_handler.send_message = AsyncMock(
        side_effect=SocketTimeoutError("timeout")
    )

    await app._poll_runtime_events()
    await app._poll_runtime_events()
    await app._poll_runtime_events()
    await app._poll_runtime_events()

    assert app.socket_handler.send_message.await_count == 3


@pytest.mark.asyncio
async def test_runtime_socket_commands_are_serialized_per_container(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    active = Mock(
        id="active-container",
        status="running",
        environment={"FLOW_RUN_ID": "42", "FLOW_TIMEZONE": "Europe/Berlin"},
    )
    active.name = "flow-2-run-1"

    app.container_manager.list_containers = AsyncMock(return_value=[active])
    app.socket_handler.is_socket_connected = Mock(return_value=True)
    app.socket_handler.setup_socket = AsyncMock()
    app._drain_container_messages = AsyncMock()

    inflight = 0
    max_inflight = 0

    async def send_message_side_effect(_container_id, _payload):
        nonlocal inflight, max_inflight
        inflight += 1
        max_inflight = max(max_inflight, inflight)
        await asyncio.sleep(0.01)
        inflight -= 1

    app.socket_handler.send_message = AsyncMock(side_effect=send_message_side_effect)
    app.socket_handler.receive_message = AsyncMock(
        return_value={"type": "runtime_ack", "ok": True}
    )

    await asyncio.gather(
        app._dispatch_cron_ticks(),
        app._poll_runtime_events(),
    )

    assert app.socket_handler.send_message.await_count == 2
    assert max_inflight == 1
