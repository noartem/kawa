"""
Socket Communication Handler for container communication via Unix sockets.

This module manages one listening socket per container and upgrades the first
accepted runtime connection into a long-lived session that is reused for all
commands and runtime events.
"""

import asyncio
import contextlib
import json
import socket
from pathlib import Path
from typing import Any, Callable, Coroutine, Dict, Optional, cast

from system_logger import SystemLogger


class SocketCommunicationError(Exception):
    """Exception raised for socket communication errors."""

    pass


class SocketTimeoutError(SocketCommunicationError):
    """Exception raised when socket operations timeout."""

    pass


class SocketConnectionError(SocketCommunicationError):
    """Exception raised when socket connection fails."""

    pass


MessageCallback = Callable[[str, dict], Coroutine[object, object, None]]
DisconnectCallback = Callable[[str], Coroutine[object, object, None]]


class SocketCommunicationHandler:
    def __init__(
        self,
        logger: SystemLogger,
        socket_dir: str = "/tmp/kawaflow/sockets",
    ):
        self.socket_dir = Path(socket_dir)
        self.socket_dir.mkdir(parents=True, exist_ok=True)
        self.logger = logger

        self._connections: Dict[str, socket.socket] = {}
        self._clients: Dict[str, socket.socket] = {}
        self._connection_status: Dict[str, bool] = {}
        self._socket_aliases: Dict[str, str] = {}
        self._accept_tasks: Dict[str, asyncio.Task] = {}
        self._reader_tasks: Dict[str, asyncio.Task] = {}
        self._connection_events: Dict[str, asyncio.Event] = {}
        self._send_locks: Dict[str, asyncio.Lock] = {}
        self._message_queues: Dict[str, asyncio.Queue] = {}
        self._message_callback: Optional[MessageCallback] = None
        self._disconnect_callback: Optional[DisconnectCallback] = None

        self.logger.debug(
            "initialized",
            {"socket_dir": str(self.socket_dir)},
        )

    def set_message_callback(self, callback: MessageCallback) -> None:
        self._message_callback = callback

    def set_disconnect_callback(self, callback: DisconnectCallback) -> None:
        self._disconnect_callback = callback

    def _get_socket_path(self, container_id: str) -> Path:
        socket_name = self._socket_aliases.get(container_id, container_id)
        return self.socket_dir / socket_name / "kawaflow.sock"

    async def setup_socket(
        self, container_id: str, socket_name: Optional[str] = None
    ) -> None:
        try:
            await self.cleanup_socket(container_id)

            self._socket_aliases[container_id] = socket_name or container_id
            socket_path = self._get_socket_path(container_id)
            socket_path.parent.mkdir(parents=True, exist_ok=True)

            if socket_path.exists():
                socket_path.unlink()
                self.logger.debug(
                    "Removed existing socket file",
                    {"container_id": container_id, "socket_path": str(socket_path)},
                )

            sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            sock.setblocking(True)
            sock.bind(str(socket_path))
            sock.listen(16)

            self._connections[container_id] = sock
            self._connection_status[container_id] = True
            self._connection_events[container_id] = asyncio.Event()
            self._send_locks[container_id] = asyncio.Lock()
            self._message_queues[container_id] = asyncio.Queue()
            self._accept_tasks[container_id] = asyncio.create_task(
                self._accept_loop(container_id)
            )

            self.logger.debug(
                "Socket setup completed",
                {"container_id": container_id, "socket_path": str(socket_path)},
            )

        except Exception as e:
            self.logger.error(
                e, {"operation": "setup_socket", "container_id": container_id}
            )
            raise SocketConnectionError(
                f"Failed to setup socket for container {container_id}: {str(e)}"
            )

    def link_socket_key(self, existing_id: str, new_id: str) -> None:
        if existing_id == new_id:
            return

        listener = self._connections.get(existing_id)
        client = self._clients.get(existing_id)
        is_connected = self._connection_status.get(existing_id, False)
        event = self._connection_events.get(existing_id)
        lock = self._send_locks.get(existing_id)
        queue = self._message_queues.get(existing_id)

        previous_accept = self._accept_tasks.pop(existing_id, None)
        if previous_accept is not None:
            previous_accept.cancel()

        previous_reader = self._reader_tasks.pop(existing_id, None)
        if previous_reader is not None:
            previous_reader.cancel()

        if listener is not None:
            self._connections[new_id] = self._connections.pop(existing_id)
        if client is not None:
            self._clients[new_id] = self._clients.pop(existing_id)
        if existing_id in self._connection_status:
            self._connection_status[new_id] = self._connection_status.pop(existing_id)
        if event is not None:
            self._connection_events[new_id] = self._connection_events.pop(existing_id)
        if lock is not None:
            self._send_locks[new_id] = self._send_locks.pop(existing_id)
        if queue is not None:
            self._message_queues[new_id] = self._message_queues.pop(existing_id)

        socket_name = self._socket_aliases.pop(existing_id, existing_id)
        self._socket_aliases[new_id] = socket_name

        if listener is not None and is_connected:
            self._accept_tasks[new_id] = asyncio.create_task(self._accept_loop(new_id))

        if client is not None:
            self._reader_tasks[new_id] = asyncio.create_task(
                self._reader_loop(new_id, client)
            )

        self.logger.debug(
            "Socket key linked",
            {
                "existing_id": existing_id,
                "new_id": new_id,
                "socket_name": socket_name,
            },
        )

    async def cleanup_socket(self, container_id: str) -> None:
        try:
            accept_task = self._accept_tasks.pop(container_id, None)
            if accept_task is not None:
                accept_task.cancel()
                with contextlib.suppress(asyncio.CancelledError):
                    await accept_task

            reader_task = self._reader_tasks.pop(container_id, None)
            if reader_task is not None:
                reader_task.cancel()
                with contextlib.suppress(asyncio.CancelledError):
                    await reader_task

            self._clear_active_client(container_id, notify=False)

            sock = self._connections.pop(container_id, None)
            if sock is not None:
                sock.close()

            socket_path = self._get_socket_path(container_id)
            if socket_path.exists():
                socket_path.unlink()
                self.logger.debug(
                    "Removed socket file",
                    {"container_id": container_id, "socket_path": str(socket_path)},
                )
            if socket_path.parent.exists():
                try:
                    socket_path.parent.rmdir()
                except OSError:
                    pass

            self._connection_status[container_id] = False
            self._connection_events.pop(container_id, None)
            self._send_locks.pop(container_id, None)
            self._message_queues.pop(container_id, None)
            self._socket_aliases.pop(container_id, None)

            self.logger.debug(
                "Socket cleanup completed", {"container_id": container_id}
            )

        except Exception as e:
            self.logger.error(
                e, {"operation": "cleanup_socket", "container_id": container_id}
            )

    def is_socket_connected(self, container_id: str) -> bool:
        return self._connection_status.get(container_id, False)

    def has_active_connection(self, container_id: str) -> bool:
        return container_id in self._clients

    def has_pending_connection(self, container_id: str) -> bool:
        queue = self._message_queues.get(container_id)
        if queue is None:
            return False

        return not queue.empty()

    async def wait_for_connection(
        self, container_id: str, timeout: Optional[float] = None
    ) -> None:
        if not self.is_socket_connected(container_id):
            raise SocketConnectionError(
                f"Socket not connected for container {container_id}"
            )

        event = self._connection_events.get(container_id)
        if event is None:
            raise SocketConnectionError(
                f"Socket not connected for container {container_id}"
            )

        try:
            if timeout is None:
                await event.wait()
            else:
                await asyncio.wait_for(event.wait(), timeout=timeout)
        except asyncio.TimeoutError as exc:
            raise SocketTimeoutError(
                f"Timeout waiting for container connection {container_id}"
            ) from exc

    async def send_message(
        self, container_id: str, message: dict, timeout: int = 30
    ) -> None:
        if not self.is_socket_connected(container_id):
            raise SocketConnectionError(
                f"Socket not connected for container {container_id}"
            )

        encoded = json.dumps(message).encode("utf-8")
        payload = len(encoded).to_bytes(4, byteorder="big") + encoded
        loop = asyncio.get_running_loop()
        deadline = loop.time() + timeout

        lock = self._send_locks.get(container_id)
        if lock is None:
            raise SocketConnectionError(
                f"No socket connection for container {container_id}"
            )

        async with lock:
            while True:
                remaining = deadline - loop.time()
                if remaining <= 0:
                    raise SocketTimeoutError(
                        f"Timeout waiting for container connection {container_id}"
                    )

                await self.wait_for_connection(container_id, timeout=remaining)
                client = self._clients.get(container_id)
                if client is None:
                    self._clear_active_client(container_id)
                    continue

                try:
                    await asyncio.wait_for(
                        loop.run_in_executor(None, client.sendall, payload),
                        timeout=remaining,
                    )
                    self.logger.communication(container_id, "sent", message)
                    return
                except asyncio.TimeoutError as exc:
                    raise SocketTimeoutError(
                        f"Timeout sending message to container {container_id}"
                    ) from exc
                except OSError:
                    self._clear_active_client(container_id)

    async def receive_message(
        self, container_id: str, timeout: Optional[int] = 30
    ) -> dict:
        queue = self._message_queues.get(container_id)
        if queue is None:
            raise SocketConnectionError(
                f"Socket not connected for container {container_id}"
            )

        try:
            if timeout is None:
                message = await queue.get()
            else:
                message = await asyncio.wait_for(queue.get(), timeout=timeout)
        except asyncio.TimeoutError as exc:
            raise SocketTimeoutError(
                f"Timeout waiting for message from container {container_id}"
            ) from exc

        return message

    async def close_all_connections(self) -> None:
        for container_id in list(self._connections.keys()):
            await self.cleanup_socket(container_id)

        self.logger.debug("All socket connections closed", {})

    async def _accept_loop(self, container_id: str) -> None:
        loop = asyncio.get_running_loop()

        while self.is_socket_connected(container_id):
            sock = self._connections.get(container_id)
            if sock is None:
                return

            try:
                client, _ = await loop.run_in_executor(None, sock.accept)
            except asyncio.CancelledError:
                raise
            except OSError:
                if not self.is_socket_connected(container_id):
                    return
                await asyncio.sleep(0.1)
                continue

            client.setblocking(True)
            self._set_active_client(container_id, client)

    def _set_active_client(self, container_id: str, client: socket.socket) -> None:
        previous_reader = self._reader_tasks.pop(container_id, None)
        if previous_reader is not None:
            previous_reader.cancel()

        previous_client = self._clients.pop(container_id, None)
        if previous_client is not None:
            with contextlib.suppress(OSError):
                previous_client.close()

        self._clients[container_id] = client
        event = self._connection_events.get(container_id)
        if event is not None:
            event.set()

        self._reader_tasks[container_id] = asyncio.create_task(
            self._reader_loop(container_id, client)
        )
        self.logger.debug(
            "Runtime connected",
            {"container_id": container_id},
        )

    async def _reader_loop(self, container_id: str, client: socket.socket) -> None:
        try:
            while self._clients.get(container_id) is client:
                message = await self._read_message_from_client(container_id, client)
                queue = self._message_queues.get(container_id)
                if queue is not None and self._message_callback is None:
                    await queue.put(message)

                self.logger.communication(container_id, "received", message)

                if self._message_callback is not None:
                    try:
                        await self._message_callback(container_id, message)
                    except asyncio.CancelledError:
                        raise
                    except Exception as exc:
                        self.logger.error(
                            exc,
                            {
                                "operation": "runtime_message_callback",
                                "container_id": container_id,
                            },
                        )
        except asyncio.CancelledError:
            raise
        except Exception as exc:
            self.logger.debug(
                "Runtime connection closed",
                {
                    "operation": "runtime_reader_loop",
                    "container_id": container_id,
                    "reason": str(exc),
                },
            )
        finally:
            self._clear_active_client(container_id)

    async def _read_message_from_client(
        self, container_id: str, client: socket.socket
    ) -> dict:
        try:
            length_bytes = await self._recv_exact(client, 4)
            message_length = int.from_bytes(length_bytes, byteorder="big")
            message_data = await self._recv_exact(client, message_length)
            return json.loads(message_data.decode("utf-8"))
        except json.JSONDecodeError as exc:
            raise SocketCommunicationError(
                f"Invalid JSON message from container {container_id}: {str(exc)}"
            ) from exc

    async def _recv_exact(self, client: socket.socket, size: int) -> bytes:
        loop = asyncio.get_running_loop()
        chunks = bytearray()

        while len(chunks) < size:
            try:
                chunk = await loop.run_in_executor(
                    None, client.recv, size - len(chunks)
                )
            except OSError as exc:
                raise SocketConnectionError("socket closed") from exc

            if not chunk:
                raise SocketConnectionError("socket closed")

            chunks.extend(chunk)

        return bytes(chunks)

    def _clear_active_client(self, container_id: str, notify: bool = True) -> None:
        client = self._clients.pop(container_id, None)
        if client is not None:
            with contextlib.suppress(OSError):
                client.close()

        event = self._connection_events.get(container_id)
        if event is not None:
            event.clear()

        if notify and self._disconnect_callback is not None:
            asyncio.create_task(
                cast(Coroutine[Any, Any, None], self._disconnect_callback(container_id))
            )
