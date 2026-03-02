"""
Tests for container resource usage monitoring functionality.

This module tests the enhanced resource usage monitoring features including:
- Periodic resource usage checks
- Resource usage thresholds and alerts
- Resource usage history tracking
- Resource alert callbacks
"""

import asyncio
import tempfile
from datetime import datetime
from unittest.mock import AsyncMock, Mock, patch

import pytest
import pytest_asyncio

from container_manager import ContainerManager
from system_logger import SystemLogger


class TestResourceUsageMonitoring:
    """Test resource usage monitoring functionality."""

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

    def test_default_resource_thresholds(self):
        """Test that default resource thresholds are set correctly."""
        thresholds = self.container_manager.get_resource_thresholds()

        assert thresholds["cpu_percent"] == 200.0
        assert thresholds["memory_percent"] == 85.0
        assert thresholds["disk_read_bytes_per_sec"] == 100 * 1024 * 1024
        assert thresholds["disk_write_bytes_per_sec"] == 100 * 1024 * 1024
        assert thresholds["network_rx_bytes_per_sec"] == 50 * 1024 * 1024
        assert thresholds["network_tx_bytes_per_sec"] == 50 * 1024 * 1024

    def test_set_resource_thresholds(self):
        """Test setting custom resource thresholds."""
        custom_thresholds = {
            "cpu_percent": 90.0,
            "memory_percent": 95.0,
            "disk_read_bytes_per_sec": 200 * 1024 * 1024,
        }

        self.container_manager.set_resource_thresholds(custom_thresholds)
        thresholds = self.container_manager.get_resource_thresholds()

        assert thresholds["cpu_percent"] == 90.0
        assert thresholds["memory_percent"] == 95.0
        assert thresholds["disk_read_bytes_per_sec"] == 200 * 1024 * 1024
        # Other thresholds should remain unchanged
        assert thresholds["network_rx_bytes_per_sec"] == 50 * 1024 * 1024

    def test_resource_monitoring_enable_disable(self):
        """Test enabling and disabling resource monitoring."""
        # Should be enabled by default
        assert self.container_manager._resource_monitoring_enabled is True

        # Test disable
        self.container_manager.disable_resource_monitoring()
        assert self.container_manager._resource_monitoring_enabled is False

        # Test enable
        self.container_manager.enable_resource_monitoring()
        assert self.container_manager._resource_monitoring_enabled is True

    def test_register_resource_alert_callback(self):
        """Test registering resource alert callbacks."""
        callback = Mock()
        self.container_manager.register_resource_alert_callback(callback)

        assert callback in self.container_manager._resource_alert_callbacks

    @pytest.mark.asyncio
    async def test_resource_usage_history_tracking(self):
        """Test that resource usage history is tracked correctly."""
        # Mock container with stats
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.stats.return_value = {
            "cpu_stats": {"cpu_usage": {"total_usage": 1000000000}},
            "precpu_stats": {"cpu_usage": {"total_usage": 500000000}},
            "memory_stats": {"usage": 268435456, "limit": 536870912},
            "networks": {"eth0": {"rx_bytes": 1024, "tx_bytes": 2048}},
            "blkio_stats": {
                "io_service_bytes_recursive": [
                    {"op": "Read", "value": 4096},
                    {"op": "Write", "value": 8192},
                ]
            },
        }

        # Check resource usage multiple times
        await self.container_manager._check_resource_usage(mock_container)
        await self.container_manager._check_resource_usage(mock_container)

        # Verify history is tracked
        history = self.container_manager.get_resource_usage_history("test_container_id")
        assert len(history) == 2
        assert "timestamp" in history[0]
        assert "cpu_percent" in history[0]
        assert "memory_usage" in history[0]

    @pytest.mark.asyncio
    async def test_resource_usage_history_limit(self):
        """Test that resource usage history is limited to 10 entries."""
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.stats.return_value = {
            "cpu_stats": {"cpu_usage": {"total_usage": 1000000000}},
            "precpu_stats": {"cpu_usage": {"total_usage": 500000000}},
            "memory_stats": {"usage": 268435456, "limit": 536870912},
            "networks": {},
            "blkio_stats": {"io_service_bytes_recursive": []},
        }

        # Add 15 entries
        for _ in range(15):
            await self.container_manager._check_resource_usage(mock_container)
            await asyncio.sleep(0.01)  # Small delay to ensure different timestamps

        # Verify only 10 entries are kept
        history = self.container_manager.get_resource_usage_history("test_container_id")
        assert len(history) == 10

    @pytest.mark.asyncio
    async def test_cpu_threshold_alert(self):
        """Test CPU threshold alert triggering."""
        # Set low CPU threshold
        self.container_manager.set_resource_thresholds({"cpu_percent": 50.0})

        # Register alert callback
        alert_callback = AsyncMock()
        self.container_manager.register_resource_alert_callback(alert_callback)

        # Mock container with high CPU usage
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.stats.return_value = {
            "cpu_stats": {
                "cpu_usage": {"total_usage": 8000000000},
                "system_cpu_usage": 10000000000,
                "online_cpus": 2,
            },
            "precpu_stats": {
                "cpu_usage": {"total_usage": 2000000000},
                "system_cpu_usage": 8000000000,
            },
            "memory_stats": {"usage": 268435456, "limit": 536870912},
            "networks": {},
            "blkio_stats": {"io_service_bytes_recursive": []},
        }

        # Check resource usage
        await self.container_manager._check_resource_usage(mock_container)

        # Verify alert callback was called
        alert_callback.assert_called_once()
        args = alert_callback.call_args[0]
        assert args[0] == "test_container_id"  # container_id
        assert args[1] == "cpu_percent"  # resource_type
        assert args[2] > 50.0  # current_value
        assert args[3] == 50.0  # threshold

    @pytest.mark.asyncio
    async def test_memory_threshold_alert(self):
        """Test memory threshold alert triggering."""
        # Set low memory threshold
        self.container_manager.set_resource_thresholds({"memory_percent": 40.0})

        # Register alert callback
        alert_callback = AsyncMock()
        self.container_manager.register_resource_alert_callback(alert_callback)

        # Mock container with high memory usage
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.stats.return_value = {
            "cpu_stats": {"cpu_usage": {"total_usage": 1000000000}},
            "precpu_stats": {"cpu_usage": {"total_usage": 500000000}},
            "memory_stats": {
                "usage": 536870912,  # 512MB
                "limit": 1073741824,  # 1GB (50% usage)
            },
            "networks": {},
            "blkio_stats": {"io_service_bytes_recursive": []},
        }

        # Check resource usage
        await self.container_manager._check_resource_usage(mock_container)

        # Verify alert callback was called
        alert_callback.assert_called_once()
        args = alert_callback.call_args[0]
        assert args[0] == "test_container_id"  # container_id
        assert args[1] == "memory_percent"  # resource_type
        assert args[2] == 50.0  # current_value
        assert args[3] == 40.0  # threshold

    @pytest.mark.asyncio
    async def test_disk_io_rate_calculation_and_alert(self):
        """Test disk I/O rate calculation and threshold alert."""
        # Set low disk I/O threshold
        self.container_manager.set_resource_thresholds(
            {
                "disk_read_bytes_per_sec": 1000,  # 1KB/s
                "disk_write_bytes_per_sec": 1000,  # 1KB/s
            }
        )

        # Register alert callback
        alert_callback = AsyncMock()
        self.container_manager.register_resource_alert_callback(alert_callback)

        mock_container = Mock()
        mock_container.id = "test_container_id"

        # First measurement
        mock_container.stats.return_value = {
            "cpu_stats": {"cpu_usage": {"total_usage": 1000000000}},
            "precpu_stats": {"cpu_usage": {"total_usage": 500000000}},
            "memory_stats": {"usage": 268435456, "limit": 536870912},
            "networks": {},
            "blkio_stats": {
                "io_service_bytes_recursive": [
                    {"op": "Read", "value": 4096},
                    {"op": "Write", "value": 8192},
                ]
            },
        }
        await self.container_manager._check_resource_usage(mock_container)

        # Wait a bit and take second measurement with higher I/O
        await asyncio.sleep(0.1)
        mock_container.stats.return_value = {
            "cpu_stats": {"cpu_usage": {"total_usage": 1000000000}},
            "precpu_stats": {"cpu_usage": {"total_usage": 500000000}},
            "memory_stats": {"usage": 268435456, "limit": 536870912},
            "networks": {},
            "blkio_stats": {
                "io_service_bytes_recursive": [
                    {"op": "Read", "value": 1048576},  # 1MB read (high rate)
                    {"op": "Write", "value": 1048576},  # 1MB write (high rate)
                ]
            },
        }
        await self.container_manager._check_resource_usage(mock_container)

        # Should have triggered alerts for both read and write rates
        assert alert_callback.call_count >= 2

    @pytest.mark.asyncio
    async def test_network_io_rate_calculation_and_alert(self):
        """Test network I/O rate calculation and threshold alert."""
        # Set low network I/O threshold
        self.container_manager.set_resource_thresholds(
            {
                "network_rx_bytes_per_sec": 1000,  # 1KB/s
                "network_tx_bytes_per_sec": 1000,  # 1KB/s
            }
        )

        # Register alert callback
        alert_callback = AsyncMock()
        self.container_manager.register_resource_alert_callback(alert_callback)

        mock_container = Mock()
        mock_container.id = "test_container_id"

        # First measurement
        mock_container.stats.return_value = {
            "cpu_stats": {"cpu_usage": {"total_usage": 1000000000}},
            "precpu_stats": {"cpu_usage": {"total_usage": 500000000}},
            "memory_stats": {"usage": 268435456, "limit": 536870912},
            "networks": {"eth0": {"rx_bytes": 1024, "tx_bytes": 2048}},
            "blkio_stats": {"io_service_bytes_recursive": []},
        }
        await self.container_manager._check_resource_usage(mock_container)

        # Wait a bit and take second measurement with higher network I/O
        await asyncio.sleep(0.1)
        mock_container.stats.return_value = {
            "cpu_stats": {"cpu_usage": {"total_usage": 1000000000}},
            "precpu_stats": {"cpu_usage": {"total_usage": 500000000}},
            "memory_stats": {"usage": 268435456, "limit": 536870912},
            "networks": {
                "eth0": {"rx_bytes": 1048576, "tx_bytes": 1048576}
            },  # High network I/O
            "blkio_stats": {"io_service_bytes_recursive": []},
        }
        await self.container_manager._check_resource_usage(mock_container)

        # Should have triggered alerts for both RX and TX rates
        assert alert_callback.call_count >= 2

    @pytest.mark.asyncio
    async def test_no_alert_when_below_threshold(self):
        """Test that no alerts are triggered when usage is below thresholds."""
        # Register alert callback
        alert_callback = AsyncMock()
        self.container_manager.register_resource_alert_callback(alert_callback)

        # Mock container with low resource usage
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.stats.return_value = {
            "cpu_stats": {
                "cpu_usage": {"total_usage": 1100000000},
                "system_cpu_usage": 10000000000,
                "online_cpus": 2,
            },
            "precpu_stats": {
                "cpu_usage": {"total_usage": 1000000000},
                "system_cpu_usage": 8000000000,
            },
            "memory_stats": {
                "usage": 107374182,  # ~100MB
                "limit": 1073741824,  # 1GB (~10% usage)
            },
            "networks": {"eth0": {"rx_bytes": 1024, "tx_bytes": 2048}},
            "blkio_stats": {
                "io_service_bytes_recursive": [
                    {"op": "Read", "value": 4096},
                    {"op": "Write", "value": 8192},
                ]
            },
        }

        # Check resource usage
        await self.container_manager._check_resource_usage(mock_container)

        # Verify no alert callback was called
        alert_callback.assert_not_called()

    @pytest.mark.asyncio
    async def test_resource_monitoring_disabled(self):
        """Test that resource monitoring is skipped when disabled."""
        # Disable resource monitoring
        self.container_manager.disable_resource_monitoring()

        # Mock container
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.attrs = {"State": {"Status": "running"}}

        # Mock the _check_resource_usage method to track if it's called
        original_method = self.container_manager._check_resource_usage
        self.container_manager._check_resource_usage = AsyncMock()

        # Check container status (which should skip resource monitoring)
        await self.container_manager._check_container_status(mock_container)

        # Verify resource monitoring was not called
        self.container_manager._check_resource_usage.assert_not_called()

        # Restore original method
        self.container_manager._check_resource_usage = original_method

    @pytest.mark.asyncio
    async def test_resource_monitoring_only_for_running_containers(self):
        """Test that resource monitoring only runs for running containers."""
        # Mock stopped container
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.attrs = {"State": {"Status": "stopped"}}

        # Mock the _check_resource_usage method to track if it's called
        original_method = self.container_manager._check_resource_usage
        self.container_manager._check_resource_usage = AsyncMock()

        # Check container status (should skip resource monitoring for stopped container)
        await self.container_manager._check_container_status(mock_container)

        # Verify resource monitoring was not called
        self.container_manager._check_resource_usage.assert_not_called()

        # Restore original method
        self.container_manager._check_resource_usage = original_method

    @pytest.mark.asyncio
    async def test_resource_usage_error_handling(self):
        """Test that resource usage monitoring handles errors gracefully."""
        # Mock container that raises exception during resource usage check
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.stats.side_effect = Exception("Stats failed")

        # Should not raise exception
        await self.container_manager._check_resource_usage(mock_container)

        # History should remain empty due to error
        history = self.container_manager.get_resource_usage_history("test_container_id")
        assert len(history) == 0

    @pytest.mark.asyncio
    async def test_resource_alert_callback_error_handling(self):
        """Test that resource alert callback errors are handled gracefully."""
        # Set low threshold
        self.container_manager.set_resource_thresholds({"cpu_percent": 10.0})

        # Register failing callback
        failing_callback = AsyncMock(side_effect=Exception("Callback failed"))
        self.container_manager.register_resource_alert_callback(failing_callback)

        # Mock container with high CPU usage
        mock_container = Mock()
        mock_container.id = "test_container_id"
        mock_container.stats.return_value = {
            "cpu_stats": {
                "cpu_usage": {"total_usage": 8000000000},
                "system_cpu_usage": 10000000000,
                "online_cpus": 2,
            },
            "precpu_stats": {
                "cpu_usage": {"total_usage": 2000000000},
                "system_cpu_usage": 8000000000,
            },
            "memory_stats": {"usage": 268435456, "limit": 536870912},
            "networks": {},
            "blkio_stats": {"io_service_bytes_recursive": []},
        }

        # Should not raise exception despite callback failure
        await self.container_manager._check_resource_usage(mock_container)

        # Verify callback was called despite exception
        failing_callback.assert_called_once()

    def test_get_resource_usage_history_with_limit(self):
        """Test getting resource usage history with custom limit."""
        container_id = "test_container_id"

        # Add some history entries
        for i in range(5):
            self.container_manager._resource_usage_history.setdefault(
                container_id, []
            ).append(
                {
                    "timestamp": datetime.now().isoformat(),
                    "cpu_percent": i * 10,
                }
            )

        # Test with limit
        history = self.container_manager.get_resource_usage_history(
            container_id, limit=3
        )
        assert len(history) == 3

        # Test with no limit
        history = self.container_manager.get_resource_usage_history(
            container_id, limit=0
        )
        assert len(history) == 5

        # Test with limit larger than available data
        history = self.container_manager.get_resource_usage_history(
            container_id, limit=10
        )
        assert len(history) == 5

    def test_get_resource_usage_history_nonexistent_container(self):
        """Test getting resource usage history for non-existent container."""
        history = self.container_manager.get_resource_usage_history("nonexistent")
        assert history == []
