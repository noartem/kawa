import asyncio
import socket
import tempfile
from pathlib import Path
from unittest.mock import AsyncMock, Mock, patch

import pytest

from socket_communication_handler import (
    SocketCommunicationHandler,
    SocketConnectionError,
    SocketTimeoutError,
)
from system_logger import SystemLogger


class TestSocketCommunicationHandler:
    @pytest.fixture
    def temp_socket_dir(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            yield temp_dir

    @pytest.fixture
    def mock_logger(self):
        return Mock(spec=SystemLogger)

    @pytest.fixture
    def handler(self, temp_socket_dir, mock_logger):
        return SocketCommunicationHandler(
            socket_dir=temp_socket_dir, logger=mock_logger
        )

    def test_init(self, temp_socket_dir, mock_logger):
        handler = SocketCommunicationHandler(
            socket_dir=temp_socket_dir, logger=mock_logger
        )

        assert handler.socket_dir == Path(temp_socket_dir)
        assert handler._connections == {}
        assert handler._clients == {}
        assert handler._connection_status == {}

    @pytest.mark.asyncio
    async def test_setup_socket_success(self, handler):
        with patch("socket.socket") as mock_socket_class:
            mock_socket = Mock()
            mock_socket_class.return_value = mock_socket

            await handler.setup_socket("container-1")

            mock_socket_class.assert_called_once_with(
                socket.AF_UNIX, socket.SOCK_STREAM
            )
            mock_socket.setblocking.assert_called_once_with(True)
            mock_socket.bind.assert_called_once()
            mock_socket.listen.assert_called_once_with(16)
            assert handler.is_socket_connected("container-1") is True

            await handler.cleanup_socket("container-1")

    @pytest.mark.asyncio
    async def test_wait_for_connection_times_out_without_client(self, handler):
        handler._connection_status["container-1"] = True
        handler._connection_events["container-1"] = asyncio.Event()

        with pytest.raises(SocketTimeoutError):
            await handler.wait_for_connection("container-1", timeout=0.01)

    @pytest.mark.asyncio
    async def test_send_message_writes_to_active_client(self, handler):
        client = Mock()
        handler._connection_status["container-1"] = True
        handler._connection_events["container-1"] = asyncio.Event()
        handler._connection_events["container-1"].set()
        handler._send_locks["container-1"] = asyncio.Lock()
        handler._clients["container-1"] = client

        await handler.send_message("container-1", {"command": "cron_tick"}, timeout=1)

        client.sendall.assert_called_once()
        handler.logger.communication.assert_called_once_with(
            "container-1",
            "sent",
            {"command": "cron_tick"},
        )

    @pytest.mark.asyncio
    async def test_send_message_requires_socket_connection(self, handler):
        with pytest.raises(SocketConnectionError):
            await handler.send_message("container-1", {"command": "cron_tick"})

    @pytest.mark.asyncio
    async def test_receive_message_reads_from_queue(self, handler):
        queue = asyncio.Queue()
        await queue.put({"type": "runtime_ack"})
        handler._message_queues["container-1"] = queue

        message = await handler.receive_message("container-1", timeout=1)

        assert message == {"type": "runtime_ack"}

    @pytest.mark.asyncio
    async def test_receive_message_times_out_without_message(self, handler):
        handler._message_queues["container-1"] = asyncio.Queue()

        with pytest.raises(SocketTimeoutError):
            await handler.receive_message("container-1", timeout=0.01)

    @pytest.mark.asyncio
    async def test_clear_active_client_triggers_disconnect_callback(self, handler):
        handler._clients["container-1"] = Mock()
        handler._connection_events["container-1"] = asyncio.Event()
        handler._connection_events["container-1"].set()
        handler.set_disconnect_callback(AsyncMock())

        handler._clear_active_client("container-1")
        await asyncio.sleep(0)

        handler._disconnect_callback.assert_awaited_once_with("container-1")

    @pytest.mark.asyncio
    async def test_reader_loop_forwards_messages_to_callback(self, handler):
        client = Mock()
        handler._clients["container-1"] = client
        handler._message_queues["container-1"] = asyncio.Queue()
        handler.set_message_callback(AsyncMock())

        messages = iter(
            [
                {"type": "runtime_hello"},
                SocketConnectionError("socket closed"),
            ]
        )

        async def read_message(_container_id, _client):
            result = next(messages)
            if isinstance(result, Exception):
                raise result
            return result

        handler._read_message_from_client = AsyncMock(side_effect=read_message)

        await handler._reader_loop("container-1", client)

        handler._message_callback.assert_awaited_once_with(
            "container-1",
            {"type": "runtime_hello"},
        )
        assert handler.has_active_connection("container-1") is False

    @pytest.mark.asyncio
    async def test_reader_loop_keeps_runtime_connected_on_callback_error(self, handler):
        client = Mock()
        handler._clients["container-1"] = client
        handler._message_queues["container-1"] = asyncio.Queue()
        handler.set_message_callback(
            AsyncMock(side_effect=[RuntimeError("boom"), None])
        )

        messages = iter(
            [
                {"type": "runtime_events", "events": []},
                {"type": "runtime_ack"},
                SocketConnectionError("socket closed"),
            ]
        )

        async def read_message(_container_id, _client):
            result = next(messages)
            if isinstance(result, Exception):
                raise result
            return result

        handler._read_message_from_client = AsyncMock(side_effect=read_message)

        await handler._reader_loop("container-1", client)

        assert handler._message_callback.await_count == 2
        assert handler._read_message_from_client.await_count == 3
        assert handler.has_active_connection("container-1") is False
