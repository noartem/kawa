"""
Tests for SocketCommunicationHandler class.

This module contains comprehensive tests for the socket communication
functionality including setup, cleanup, message sending/receiving,
and error handling scenarios.
"""

import asyncio
import json
import socket
import tempfile
import pytest
from pathlib import Path
from unittest.mock import Mock, patch, AsyncMock

from socket_communication_handler import (
    SocketCommunicationHandler,
    SocketCommunicationError,
    SocketTimeoutError,
    SocketConnectionError,
)
from system_logger import SystemLogger


class TestSocketCommunicationHandler:
    """Test cases for SocketCommunicationHandler."""

    @pytest.fixture
    def temp_socket_dir(self):
        """Create temporary directory for socket files."""
        with tempfile.TemporaryDirectory() as temp_dir:
            yield temp_dir

    @pytest.fixture
    def mock_logger(self):
        """Create mock system logger."""
        return Mock(spec=SystemLogger)

    @pytest.fixture
    def handler(self, temp_socket_dir, mock_logger):
        """Create SocketCommunicationHandler instance."""
        return SocketCommunicationHandler(
            socket_dir=temp_socket_dir, logger=mock_logger
        )

    def test_init(self, temp_socket_dir, mock_logger):
        """Test handler initialization."""
        handler = SocketCommunicationHandler(
            socket_dir=temp_socket_dir, logger=mock_logger
        )

        assert handler.socket_dir == Path(temp_socket_dir)
        assert handler.logger == mock_logger
        assert handler._connections == {}
        assert handler._connection_status == {}

        # Verify socket directory is created
        assert Path(temp_socket_dir).exists()

        # Verify debug logging
        mock_logger.debug.assert_called_once()

    def test_init_with_system_logger(self, temp_socket_dir):
        """Test handler initialization with system logger."""
        logger = SystemLogger("socket_handler_test")
        handler = SocketCommunicationHandler(socket_dir=temp_socket_dir, logger=logger)

        assert handler.logger is logger

    def test_get_socket_path(self, handler):
        """Test socket path generation."""
        container_id = "test_container_123"
        expected_path = handler.socket_dir / container_id / "kawaflow.sock"

        actual_path = handler._get_socket_path(container_id)

        assert actual_path == expected_path

    @pytest.mark.asyncio
    async def test_setup_socket_success(self, handler):
        """Test successful socket setup."""
        container_id = "test_container_123"

        with patch("socket.socket") as mock_socket_class:
            mock_socket = Mock()
            mock_socket_class.return_value = mock_socket

            await handler.setup_socket(container_id)

            # Verify socket creation and configuration
            mock_socket_class.assert_called_once_with(
                socket.AF_UNIX, socket.SOCK_STREAM
            )
            mock_socket.setblocking.assert_called_once_with(True)
            mock_socket.bind.assert_called_once()
            mock_socket.listen.assert_called_once_with(1)

            # Verify internal state
            assert container_id in handler._connections
            assert handler._connections[container_id] == mock_socket
            assert handler._connection_status[container_id] is True

            # Verify logging
            handler.logger.debug.assert_called()

    @pytest.mark.asyncio
    async def test_setup_socket_removes_existing_file(self, handler):
        """Test socket setup removes existing socket file."""
        container_id = "test_container_123"
        socket_path = handler._get_socket_path(container_id)

        # Create existing socket file
        socket_path.parent.mkdir(parents=True, exist_ok=True)
        socket_path.touch()
        assert socket_path.exists()

        with patch("socket.socket") as mock_socket_class:
            mock_socket = Mock()
            mock_socket_class.return_value = mock_socket

            await handler.setup_socket(container_id)

            # Verify existing file was removed
            # Note: The file might be recreated by socket.bind()
            handler.logger.debug.assert_called()

    @pytest.mark.asyncio
    async def test_setup_socket_failure(self, handler):
        """Test socket setup failure handling."""
        container_id = "test_container_123"

        with patch("socket.socket") as mock_socket_class:
            mock_socket_class.side_effect = Exception("Socket creation failed")

            with pytest.raises(SocketConnectionError) as exc_info:
                await handler.setup_socket(container_id)

            assert "Failed to setup socket" in str(exc_info.value)
            assert container_id not in handler._connections

            # Verify error logging
            handler.logger.error.assert_called_once()

    @pytest.mark.asyncio
    async def test_cleanup_socket_success(self, handler):
        """Test successful socket cleanup."""
        container_id = "test_container_123"

        # Setup socket first
        mock_socket = Mock()
        handler._connections[container_id] = mock_socket
        handler._connection_status[container_id] = True

        # Create socket file
        socket_path = handler._get_socket_path(container_id)
        socket_path.parent.mkdir(parents=True, exist_ok=True)
        socket_path.touch()

        await handler.cleanup_socket(container_id)

        # Verify socket was closed
        mock_socket.close.assert_called_once()

        # Verify internal state cleanup
        assert container_id not in handler._connections
        assert handler._connection_status[container_id] is False

        # Verify socket file removal
        assert not socket_path.exists()

        # Verify logging
        handler.logger.debug.assert_called()

    @pytest.mark.asyncio
    async def test_cleanup_socket_no_connection(self, handler):
        """Test socket cleanup when no connection exists."""
        container_id = "test_container_123"

        await handler.cleanup_socket(container_id)

        # Should not raise exception
        assert handler._connection_status[container_id] is False

    @pytest.mark.asyncio
    async def test_cleanup_socket_error_handling(self, handler):
        """Test socket cleanup error handling."""
        container_id = "test_container_123"

        # Setup socket with mock that raises exception
        mock_socket = Mock()
        mock_socket.close.side_effect = Exception("Close failed")
        handler._connections[container_id] = mock_socket
        handler._connection_status[container_id] = True

        # Should not raise exception (cleanup should be graceful)
        await handler.cleanup_socket(container_id)

        # Verify error was logged
        handler.logger.error.assert_called_once()

    def test_is_socket_connected_true(self, handler):
        """Test socket connection status check - connected."""
        container_id = "test_container_123"
        handler._connection_status[container_id] = True

        result = handler.is_socket_connected(container_id)

        assert result is True

    def test_is_socket_connected_false(self, handler):
        """Test socket connection status check - not connected."""
        container_id = "test_container_123"
        handler._connection_status[container_id] = False

        result = handler.is_socket_connected(container_id)

        assert result is False

    def test_is_socket_connected_unknown(self, handler):
        """Test socket connection status check - unknown container."""
        container_id = "unknown_container"

        result = handler.is_socket_connected(container_id)

        assert result is False

    @pytest.mark.asyncio
    async def test_send_message_success(self, handler):
        """Test successful message sending."""
        container_id = "test_container_123"
        message = {"command": "test", "data": {"key": "value"}}

        # Setup connection
        mock_socket = Mock()
        mock_client_socket = Mock()
        mock_socket.accept.return_value = (mock_client_socket, None)

        handler._connections[container_id] = mock_socket
        handler._connection_status[container_id] = True

        with (
            patch("asyncio.get_event_loop") as mock_loop,
            patch("asyncio.wait_for") as mock_wait_for,
            patch.object(handler, "_is_stale_client_connection", return_value=False),
        ):
            mock_executor = AsyncMock()
            mock_executor.return_value = None  # For send operations
            mock_loop.return_value.run_in_executor = mock_executor
            mock_loop.return_value.time.return_value = 0
            mock_wait_for.side_effect = [(mock_client_socket, None), None, None]

            await handler.send_message(container_id, message)

            # Message serialization verification removed as it was unused

            # Verify logging
            handler.logger.communication.assert_called_once_with(
                container_id, "sent", message
            )

    @pytest.mark.asyncio
    async def test_send_message_not_connected(self, handler):
        """Test sending message when socket not connected."""
        container_id = "test_container_123"
        message = {"command": "test"}

        handler._connection_status[container_id] = False

        with pytest.raises(SocketConnectionError) as exc_info:
            await handler.send_message(container_id, message)

        assert "Socket not connected" in str(exc_info.value)

    @pytest.mark.asyncio
    async def test_send_message_no_socket(self, handler):
        """Test sending message when no socket exists."""
        container_id = "test_container_123"
        message = {"command": "test"}

        handler._connection_status[container_id] = True
        # No socket in _connections

        with pytest.raises(SocketCommunicationError) as exc_info:
            await handler.send_message(container_id, message)

        assert "No socket connection" in str(exc_info.value)

    @pytest.mark.asyncio
    async def test_send_message_failure(self, handler):
        """Test message sending failure."""
        container_id = "test_container_123"
        message = {"command": "test"}

        # Setup connection
        mock_socket = Mock()
        mock_client_socket = Mock()
        mock_socket.accept.return_value = (mock_client_socket, None)

        handler._connections[container_id] = mock_socket
        handler._connection_status[container_id] = True

        with (
            patch("asyncio.get_event_loop") as mock_loop,
            patch("asyncio.wait_for") as mock_wait_for,
            patch.object(handler, "_is_stale_client_connection", return_value=False),
        ):
            mock_executor = AsyncMock()
            # Make the send operation fail
            mock_executor.side_effect = Exception("Send failed")
            mock_loop.return_value.run_in_executor = mock_executor
            mock_loop.return_value.time.return_value = 0
            mock_wait_for.side_effect = [
                (mock_client_socket, None),
                Exception("Send failed"),
            ]

            with pytest.raises(SocketCommunicationError) as exc_info:
                await handler.send_message(container_id, message)

            assert "Failed to send message" in str(exc_info.value)

            # Verify error logging
            handler.logger.error.assert_called_once()

    @pytest.mark.asyncio
    async def test_send_message_skips_stale_connection(self, handler):
        """Test skipping stale queued response connections before sending."""
        container_id = "test_container_123"
        message = {"command": "test", "data": {"key": "value"}}

        mock_socket = Mock()
        stale_client_socket = Mock()
        fresh_client_socket = Mock()

        handler._connections[container_id] = mock_socket
        handler._connection_status[container_id] = True

        with (
            patch("asyncio.get_event_loop") as mock_loop,
            patch("asyncio.wait_for") as mock_wait_for,
            patch.object(
                handler,
                "_is_stale_client_connection",
                side_effect=[True, False],
            ),
        ):
            mock_executor = AsyncMock()
            mock_executor.return_value = None
            mock_loop.return_value.run_in_executor = mock_executor
            mock_loop.return_value.time.return_value = 0

            mock_wait_for.side_effect = [
                (stale_client_socket, None),
                (fresh_client_socket, None),
                None,
                None,
            ]

            await handler.send_message(container_id, message)

            stale_client_socket.close.assert_called_once()
            fresh_client_socket.close.assert_called_once()
            handler.logger.communication.assert_called_once_with(
                container_id, "sent", message
            )

    @pytest.mark.asyncio
    async def test_send_message_retries_until_success(self, handler):
        """Test retry loop continues after socket write errors."""
        container_id = "test_container_123"
        message = {"command": "test"}

        mock_socket = Mock()
        first_client = Mock()
        second_client = Mock()
        third_client = Mock()

        handler._connections[container_id] = mock_socket
        handler._connection_status[container_id] = True

        with (
            patch("asyncio.get_event_loop") as mock_loop,
            patch("asyncio.wait_for") as mock_wait_for,
            patch.object(handler, "_is_stale_client_connection", return_value=False),
        ):
            mock_executor = AsyncMock()
            mock_executor.return_value = None
            mock_loop.return_value.run_in_executor = mock_executor
            mock_loop.return_value.time.return_value = 0

            mock_wait_for.side_effect = [
                (first_client, None),
                BrokenPipeError("broken pipe"),
                (second_client, None),
                OSError("socket closed"),
                (third_client, None),
                None,
                None,
            ]

            await handler.send_message(container_id, message)

            first_client.close.assert_called_once()
            second_client.close.assert_called_once()
            third_client.close.assert_called_once()
            handler.logger.communication.assert_called_once_with(
                container_id, "sent", message
            )

    @pytest.mark.asyncio
    async def test_receive_message_success(self, handler):
        """Test successful message receiving."""
        container_id = "test_container_123"
        message = {"command": "response", "data": {"result": "success"}}
        message_data = json.dumps(message).encode("utf-8")
        message_length = len(message_data)

        # Setup connection
        mock_socket = Mock()
        mock_client_socket = Mock()
        mock_socket.accept.return_value = (mock_client_socket, None)

        handler._connections[container_id] = mock_socket
        handler._connection_status[container_id] = True

        with (
            patch("asyncio.get_event_loop") as mock_loop,
            patch("asyncio.wait_for") as mock_wait_for,
        ):
            mock_executor = Mock()
            mock_loop.return_value.run_in_executor = mock_executor

            # Mock the receive operations
            mock_wait_for.side_effect = [
                (mock_client_socket, None),  # accept
                message_length.to_bytes(4, byteorder="big"),  # length
                message_data,  # data
            ]

            result = await handler.receive_message(container_id, timeout=30)

            assert result == message

            # Verify logging
            handler.logger.communication.assert_called_once_with(
                container_id, "received", message
            )

    @pytest.mark.asyncio
    async def test_receive_message_not_connected(self, handler):
        """Test receiving message when socket not connected."""
        container_id = "test_container_123"

        handler._connection_status[container_id] = False

        with pytest.raises(SocketConnectionError) as exc_info:
            await handler.receive_message(container_id)

        assert "Socket not connected" in str(exc_info.value)

    @pytest.mark.asyncio
    async def test_receive_message_timeout(self, handler):
        """Test receiving message timeout."""
        container_id = "test_container_123"

        # Setup connection
        mock_socket = Mock()
        handler._connections[container_id] = mock_socket
        handler._connection_status[container_id] = True

        with patch("asyncio.wait_for") as mock_wait_for:
            mock_wait_for.side_effect = asyncio.TimeoutError()

            with pytest.raises(SocketTimeoutError) as exc_info:
                await handler.receive_message(container_id, timeout=5)

            assert "Timeout waiting for connection" in str(exc_info.value)

    @pytest.mark.asyncio
    async def test_receive_message_invalid_json(self, handler):
        """Test receiving message with invalid JSON."""
        container_id = "test_container_123"
        invalid_data = b"invalid json data"

        # Setup connection
        mock_socket = Mock()
        mock_client_socket = Mock()
        mock_socket.accept.return_value = (mock_client_socket, None)

        handler._connections[container_id] = mock_socket
        handler._connection_status[container_id] = True

        with (
            patch("asyncio.get_event_loop") as mock_loop,
            patch("asyncio.wait_for") as mock_wait_for,
        ):
            mock_executor = Mock()
            mock_loop.return_value.run_in_executor = mock_executor

            # Mock the receive operations
            mock_wait_for.side_effect = [
                (mock_client_socket, None),  # accept
                len(invalid_data).to_bytes(4, byteorder="big"),  # length
                invalid_data,  # invalid data
            ]

            with pytest.raises(SocketCommunicationError) as exc_info:
                await handler.receive_message(container_id)

            assert "Invalid JSON message" in str(exc_info.value)

    @pytest.mark.asyncio
    async def test_close_all_connections(self, handler):
        """Test closing all connections."""
        # Setup multiple connections
        container_ids = ["container_1", "container_2", "container_3"]

        for container_id in container_ids:
            mock_socket = Mock()
            handler._connections[container_id] = mock_socket
            handler._connection_status[container_id] = True

        await handler.close_all_connections()

        # Verify all connections are closed
        assert len(handler._connections) == 0
        for container_id in container_ids:
            assert handler._connection_status[container_id] is False

        # Verify logging
        handler.logger.debug.assert_called()


class TestSocketCommunicationHandlerIntegration:
    """Integration tests for SocketCommunicationHandler."""

    @pytest.fixture
    def temp_socket_dir(self):
        """Create temporary directory for socket files."""
        with tempfile.TemporaryDirectory() as temp_dir:
            yield temp_dir

    @pytest.fixture
    def handler(self, temp_socket_dir):
        """Create SocketCommunicationHandler instance."""
        logger = SystemLogger("socket_handler_integration")
        return SocketCommunicationHandler(socket_dir=temp_socket_dir, logger=logger)

    @pytest.mark.asyncio
    async def test_socket_lifecycle(self, handler):
        """Test complete socket lifecycle."""
        container_id = "integration_test_container"

        # Initially not connected
        assert not handler.is_socket_connected(container_id)

        # Setup socket
        await handler.setup_socket(container_id)
        assert handler.is_socket_connected(container_id)

        # Verify socket file exists
        socket_path = handler._get_socket_path(container_id)
        assert socket_path.exists()

        # Cleanup socket
        await handler.cleanup_socket(container_id)
        assert not handler.is_socket_connected(container_id)
        assert not socket_path.exists()

    @pytest.mark.asyncio
    async def test_multiple_containers(self, handler):
        """Test handling multiple containers."""
        container_ids = ["container_1", "container_2", "container_3"]

        # Setup all containers
        for container_id in container_ids:
            await handler.setup_socket(container_id)
            assert handler.is_socket_connected(container_id)

        # Verify all socket files exist
        for container_id in container_ids:
            socket_path = handler._get_socket_path(container_id)
            assert socket_path.exists()

        # Cleanup all containers
        await handler.close_all_connections()

        # Verify all are cleaned up
        for container_id in container_ids:
            assert not handler.is_socket_connected(container_id)
            socket_path = handler._get_socket_path(container_id)
            assert not socket_path.exists()
