"""
Tests for container status monitoring functionality.

This module tests the container status monitoring features including:
- Resource usage monitoring
- Status change detection and notification
- Health check monitoring
- Container crash detection
"""

import asyncio
import os
import tempfile
from unittest.mock import AsyncMock, Mock, patch

import pytest
import pytest_asyncio

from container_manager import ContainerManager
from models import ContainerHealth, ContainerState, ContainerStatus
from system_logger import SystemLogger


@pytest.mark.asyncio
class TestContainerStatusMonitoring:
    """Test container status monitoring functionality."""

    @pytest_asyncio.fixture(autouse=True)
    async def setup_test(self):
        """Set up test fixtures and tear down after test."""
        # Setup
        self.temp_dir = tempfile.mkdtemp()

        # Create a patcher for Docker client
        self.docker_patcher = patch("container_manager.docker")
        self.mock_docker = self.docker_patcher.start()

        # Mock Docker client to avoid connection issues
        self.mock_docker_client = Mock()
        self.mock_docker.from_env.return_value = self.mock_docker_client

        # Create container manager with mocked Docker
        self.container_manager = ContainerManager(
            logger=Mock(spec=SystemLogger), socket_dir=self.temp_dir
        )
        self.container_manager.docker_client = self.mock_docker_client

        yield

        # Teardown
        self.docker_patcher.stop()

        import shutil

        shutil.rmtree(self.temp_dir, ignore_errors=True)

    @pytest.mark.asyncio
    @patch("container_manager.docker")
    async def test_get_resource_usage(self, _):
        """Test resource usage monitoring."""
        # Mock container with stats
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.stats.return_value = {
            "cpu_stats": {
                "cpu_usage": {"total_usage": 2000000000},
                "system_cpu_usage": 10000000000,
                "online_cpus": 2,
            },
            "precpu_stats": {
                "cpu_usage": {"total_usage": 1000000000},
                "system_cpu_usage": 8000000000,
            },
            "memory_stats": {
                "usage": 536870912,  # 512MB
                "limit": 1073741824,  # 1GB
            },
            "networks": {"eth0": {"rx_bytes": 1024, "tx_bytes": 2048}},
            "blkio_stats": {
                "io_service_bytes_recursive": [
                    {"op": "Read", "value": 4096},
                    {"op": "Write", "value": 8192},
                ]
            },
        }

        # Test resource usage calculation
        resource_usage = await self.container_manager._get_resource_usage(
            mock_container
        )

        # Verify CPU calculation
        # CPU delta: 2000000000 - 1000000000 = 1000000000
        # System delta: 10000000000 - 8000000000 = 2000000000
        # CPU usage: (1000000000 / 2000000000) * 2 * 100 = 100.0%
        assert resource_usage["cpu_percent"] == 100.0

        # Verify memory calculation
        # Memory usage: 536870912 / 1073741824 * 100 = 50.0%
        assert resource_usage["memory_usage"] == 536870912
        assert resource_usage["memory_limit"] == 1073741824
        assert resource_usage["memory_percent"] == 50.0

        # Verify network stats
        assert resource_usage["network_rx_bytes"] == 1024
        assert resource_usage["network_tx_bytes"] == 2048

        # Verify disk I/O stats
        assert resource_usage["disk_read_bytes"] == 4096
        assert resource_usage["disk_write_bytes"] == 8192

        # Verify timestamp is present
        assert "timestamp" in resource_usage

    @pytest.mark.asyncio
    @patch("container_manager.docker")
    async def test_get_container_status_with_resource_usage(self, _):
        """Test get_container_status includes resource usage."""
        # Mock Docker client and container
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.name = "test_container"
        mock_container.attrs = {
            "State": {
                "Status": "running",
                "StartedAt": "2025-01-19T10:00:00.000000000Z",
                "Health": {"Status": "healthy"},
            }
        }
        mock_container.stats.return_value = {
            "cpu_stats": {"cpu_usage": {"total_usage": 1000000000}},
            "precpu_stats": {"cpu_usage": {"total_usage": 500000000}},
            "memory_stats": {"usage": 268435456, "limit": 536870912},
            "networks": {},
            "blkio_stats": {"io_service_bytes_recursive": []},
        }

        self.container_manager.docker_client.containers.get.return_value = (
            mock_container
        )

        # Create socket file
        socket_dir = os.path.join(self.temp_dir, "test_container")
        os.makedirs(socket_dir, exist_ok=True)
        socket_path = os.path.join(socket_dir, "kawaflow.sock")
        with open(socket_path, "w") as f:
            f.write("")

        # Test get_container_status
        status = await self.container_manager.get_container_status("test_container_id")

        # Verify status includes resource usage
        assert isinstance(status, ContainerStatus)
        assert status.id == "test_container_id"
        assert status.state == ContainerState.RUNNING
        assert status.health == ContainerHealth.HEALTHY
        assert status.socket_connected is True
        assert isinstance(status.resource_usage, dict)
        assert "memory_usage" in status.resource_usage

    async def test_callback_registration(self):
        """Test callback registration methods."""
        # Test status change callback registration
        status_callback = Mock()
        self.container_manager.register_status_change_callback(status_callback)
        assert status_callback in self.container_manager._status_change_callbacks

        # Test health check callback registration
        health_callback = Mock()
        self.container_manager.register_health_check_callback(health_callback)
        assert health_callback in self.container_manager._health_check_callbacks

        # Test crash callback registration
        crash_callback = Mock()
        self.container_manager.register_crash_callback(crash_callback)
        assert crash_callback in self.container_manager._crash_callbacks

    @pytest.mark.asyncio
    async def test_start_stop_monitoring(self):
        """Test monitoring lifecycle."""
        # Test start monitoring
        assert self.container_manager._monitoring_active is False
        await self.container_manager.start_monitoring()
        assert self.container_manager._monitoring_active is True
        assert self.container_manager._monitoring_task is not None

        # Test start monitoring when already active
        await self.container_manager.start_monitoring()  # Should not create new task
        assert self.container_manager._monitoring_active is True

        # Test stop monitoring
        await self.container_manager.stop_monitoring()
        assert self.container_manager._monitoring_active is False
        assert self.container_manager._monitoring_task is None

    @pytest.mark.asyncio
    @patch("container_manager.docker")
    async def test_status_change_detection(self, _):
        """Test container status change detection and notification."""
        # Mock container
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.attrs = {"State": {"Status": "running"}}

        # Set up initial state
        self.container_manager._container_states["test_container_id"] = (
            ContainerState.STOPPED
        )

        # Register callback
        status_callback = AsyncMock()
        self.container_manager.register_status_change_callback(status_callback)

        # Test status change detection
        await self.container_manager._check_container_status(mock_container)

        # Verify callback was called
        status_callback.assert_called_once_with(
            "test_container_id", ContainerState.STOPPED, ContainerState.RUNNING
        )

        # Verify state was updated
        assert (
            self.container_manager._container_states["test_container_id"]
            == ContainerState.RUNNING
        )

    @pytest.mark.asyncio
    @patch("container_manager.docker")
    async def test_container_crash_detection(self, _):
        """Test container crash detection and notification."""
        # Mock crashed container
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.attrs = {
            "State": {
                "Status": "exited",
                "ExitCode": 1,
                "FinishedAt": "2025-01-19T10:30:00.000000000Z",
                "Error": "Container crashed",
                "OOMKilled": False,
                "Pid": 0,
            }
        }

        # Register crash callback
        crash_callback = AsyncMock()
        self.container_manager.register_crash_callback(crash_callback)

        # Test crash detection
        await self.container_manager._check_container_crash(mock_container)

        # Verify crash callback was called
        crash_callback.assert_called_once()
        args = crash_callback.call_args[0]
        assert args[0] == "test_container_id"  # container_id
        assert args[1] == 1  # exit_code
        assert isinstance(args[2], dict)  # crash_details
        assert args[2]["exit_code"] == 1
        assert args[2]["error"] == "Container crashed"

    @pytest.mark.asyncio
    @patch("container_manager.docker")
    async def test_container_crash_detection_deduplicated(self, _):
        """Test duplicate crash notifications are suppressed."""
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.attrs = {
            "State": {
                "Status": "exited",
                "ExitCode": 1,
                "FinishedAt": "2025-01-19T10:30:00.000000000Z",
                "Error": "Container crashed",
                "OOMKilled": False,
                "Pid": 0,
            }
        }

        crash_callback = AsyncMock()
        self.container_manager.register_crash_callback(crash_callback)

        await self.container_manager._check_container_crash(mock_container)
        await self.container_manager._check_container_crash(mock_container)

        crash_callback.assert_called_once()

    @pytest.mark.asyncio
    @patch("container_manager.docker")
    async def test_health_check_monitoring(self, _):
        """Test container health check monitoring."""
        # Mock unhealthy container
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.attrs = {
            "State": {
                "Health": {
                    "Status": "unhealthy",
                    "FailingStreak": 3,
                    "Log": [{"Output": "Health check failed", "ExitCode": 1}],
                }
            }
        }

        # Register health callback
        health_callback = AsyncMock()
        self.container_manager.register_health_check_callback(health_callback)

        # Test health check monitoring
        await self.container_manager._check_container_health(mock_container)

        # Verify health callback was called
        health_callback.assert_called_once_with(
            "test_container_id", ContainerHealth.UNHEALTHY
        )

    @pytest.mark.asyncio
    async def test_safe_callback_async(self):
        """Test safe callback execution with async function."""
        async_callback = AsyncMock()
        await self.container_manager._safe_callback(async_callback, "arg1", "arg2")
        async_callback.assert_called_once_with("arg1", "arg2")

    @pytest.mark.asyncio
    async def test_safe_callback_sync(self):
        """Test safe callback execution with sync function."""
        sync_callback = Mock()
        await self.container_manager._safe_callback(sync_callback, "arg1", "arg2")
        sync_callback.assert_called_once_with("arg1", "arg2")

    @pytest.mark.asyncio
    async def test_safe_callback_exception_handling(self):
        """Test safe callback handles exceptions gracefully."""
        failing_callback = Mock(side_effect=Exception("Callback failed"))

        # Should not raise exception
        await self.container_manager._safe_callback(failing_callback, "arg1")

        # Callback should have been called despite exception
        failing_callback.assert_called_once_with("arg1")

    @pytest.mark.asyncio
    @patch("container_manager.docker")
    async def test_monitoring_loop_integration(self, _):
        """Test the monitoring loop integration."""
        # Mock containers list
        mock_container1 = Mock()
        mock_container1.id = "container1"
        mock_container1.attrs = {"State": {"Status": "running"}}

        mock_container2 = Mock()
        mock_container2.id = "container2"
        mock_container2.attrs = {"State": {"Status": "stopped"}}

        self.container_manager.docker_client.containers.list.return_value = [
            mock_container1,
            mock_container2,
        ]

        # Register callbacks
        status_callback = AsyncMock()
        self.container_manager.register_status_change_callback(status_callback)

        # Start monitoring briefly
        await self.container_manager.start_monitoring()

        # Let it run for a short time
        await asyncio.sleep(0.1)

        # Stop monitoring
        await self.container_manager.stop_monitoring()

        # Verify containers were checked
        self.container_manager.docker_client.containers.list.assert_called()

    @pytest.mark.asyncio
    @patch("container_manager.docker")
    async def test_resource_usage_error_handling(self, _):
        """Test resource usage monitoring handles errors gracefully."""
        # Mock container that raises exception on stats()
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.stats.side_effect = Exception("Stats failed")

        # Test resource usage with error
        resource_usage = await self.container_manager._get_resource_usage(
            mock_container
        )

        # Should return empty dict on error
        assert resource_usage == {}

    @pytest.mark.asyncio
    @patch("container_manager.docker")
    async def test_get_resource_usage_with_nullable_io_stats(self, _):
        """Test resource usage with nullable network and blkio fields."""
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.stats.return_value = {
            "cpu_stats": {
                "cpu_usage": {"total_usage": 2000000000},
                "system_cpu_usage": 10000000000,
                "online_cpus": 2,
            },
            "precpu_stats": {
                "cpu_usage": {"total_usage": 1000000000},
                "system_cpu_usage": 8000000000,
            },
            "memory_stats": {"usage": 1, "limit": 2},
            "networks": None,
            "blkio_stats": {"io_service_bytes_recursive": None},
        }

        resource_usage = await self.container_manager._get_resource_usage(
            mock_container
        )

        assert resource_usage["network_rx_bytes"] == 0
        assert resource_usage["network_tx_bytes"] == 0
        assert resource_usage["disk_read_bytes"] == 0
        assert resource_usage["disk_write_bytes"] == 0

    @pytest.mark.asyncio
    async def test_no_status_change_no_callback(self):
        """Test that callbacks are not called when status doesn't change."""
        # Mock container
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.attrs = {"State": {"Status": "running"}}

        # Set same initial state
        self.container_manager._container_states["test_container_id"] = (
            ContainerState.RUNNING
        )

        # Register callback
        status_callback = AsyncMock()
        self.container_manager.register_status_change_callback(status_callback)

        # Test status check with no change
        await self.container_manager._check_container_status(mock_container)

        # Verify callback was NOT called
        status_callback.assert_not_called()

    @pytest.mark.asyncio
    @patch("container_manager.docker")
    async def test_container_not_crashed_with_zero_exit_code(self, _):
        """Test that containers with exit code 0 are not considered crashed."""
        # Mock container with normal exit
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.attrs = {
            "State": {
                "Status": "exited",
                "ExitCode": 0,  # Normal exit
                "FinishedAt": "2025-01-19T10:30:00.000000000Z",
                "Error": "",
                "OOMKilled": False,
                "Pid": 0,
            }
        }

        # Register crash callback
        crash_callback = AsyncMock()
        self.container_manager.register_crash_callback(crash_callback)

        # Test crash detection
        await self.container_manager._check_container_crash(mock_container)

        # Verify crash callback was NOT called for normal exit
        crash_callback.assert_not_called()
