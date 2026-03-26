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
async def test_startup_does_not_block_on_restore_runtime_sockets(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    restore_started = asyncio.Event()
    release_restore = asyncio.Event()

    async def slow_restore() -> None:
        restore_started.set()
        await release_restore.wait()

    app.messaging.connect = AsyncMock()
    app.container_manager.start_monitoring = AsyncMock()
    app.event_handler.start = AsyncMock()
    app._restore_runtime_sockets = AsyncMock(side_effect=slow_restore)

    startup_task = asyncio.create_task(app.startup())

    await restore_started.wait()
    await asyncio.wait_for(startup_task, timeout=1)

    assert app._started is True
    assert len(app._background_tasks) == 3

    release_restore.set()
    await app.shutdown()


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


def test_active_flow_deployments_filters_non_running_or_non_flow(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    active = Mock(id="active", status="running", environment={"FLOW_RUN_ID": "42"})
    inactive = Mock(id="inactive", status="created", environment={"FLOW_RUN_ID": "42"})
    non_flow = Mock(id="non-flow", status="running", environment={})

    result = app._active_flow_deployments([active, inactive, non_flow])

    assert result == [active]


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
async def test_handle_runtime_socket_message_marks_runtime_ready(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    app.socket_handler.send_message = AsyncMock()

    await app._handle_runtime_socket_message(
        "container-1",
        {"type": "runtime_hello", "flow_id": "7", "flow_run_id": "11"},
    )

    assert "container-1" in app._runtime_ready_containers
    app.socket_handler.send_message.assert_awaited_once_with(
        "container-1",
        {"command": "dump"},
        timeout=5,
    )


@pytest.mark.asyncio
async def test_handle_runtime_socket_message_publishes_runtime_graph(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    app.messaging.publish_event = AsyncMock()

    await app._handle_runtime_socket_message(
        "container-1",
        {
            "type": "runtime_graph",
            "flow_id": "7",
            "flow_run_id": "11",
            "graph": {
                "events": [{"id": "CronEvent"}],
                "actors": [{"id": "Starter", "receives": ["CronEvent"]}],
                "nodes": [],
                "edges": [],
            },
        },
    )

    app.messaging.publish_event.assert_awaited_once_with(
        "runtime_graph_updated",
        {
            "container_id": "container-1",
            "flow_id": "7",
            "flow_run_id": "11",
            "events": [{"id": "CronEvent"}],
            "actors": [{"id": "Starter", "receives": ["CronEvent"]}],
        },
    )


@pytest.mark.asyncio
async def test_handle_runtime_disconnect_clears_runtime_ready(monkeypatch):
    monkeypatch.setenv("MESSAGING_BACKEND", "inmemory")
    with patch("docker.from_env") as mock_docker:
        mock_docker.return_value = Mock()
        app = FlowManagerApp(socket_dir="/tmp/test_sockets")

    app._runtime_ready_containers.add("container-1")

    await app._handle_runtime_disconnect("container-1")

    assert "container-1" not in app._runtime_ready_containers


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
    app.socket_handler.wait_for_connection = AsyncMock()
    app.socket_handler.send_message = AsyncMock()

    await app._dispatch_cron_ticks()

    app.socket_handler.send_message.assert_called_once_with(
        "active-container",
        {
            "command": "cron_tick",
            "data": {"timezone": "Europe/Berlin"},
        },
        timeout=5,
    )
    app.socket_handler.wait_for_connection.assert_called_once_with(
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
async def test_dispatch_cron_ticks_skips_disconnected_runtime_on_timeout(monkeypatch):
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
    app.socket_handler.is_socket_connected = Mock(return_value=True)
    app.socket_handler.wait_for_connection = AsyncMock(
        side_effect=SocketTimeoutError("timeout")
    )
    app.socket_handler.send_message = AsyncMock()

    await app._dispatch_cron_ticks()

    app.socket_handler.send_message.assert_not_called()
