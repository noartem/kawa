"""
Socket Communication Handler for container communication via Unix sockets.

This module provides the SocketCommunicationHandler class that manages
bidirectional communication with flow containers through Unix socket files.
Each container has a dedicated socket path {container_name}/kawaflow.sock.
"""

import asyncio
import json
import select
import socket
from pathlib import Path
from typing import Dict, Optional

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


class SocketCommunicationHandler:
    def __init__(
        self,
        logger: SystemLogger,
        socket_dir: str = "/tmp/kawaflow/sockets",
    ):
        self.socket_dir = Path(socket_dir)
        self.socket_dir.mkdir(parents=True, exist_ok=True)
        self.logger = logger

        # Track active socket connections
        self._connections: Dict[str, socket.socket] = {}
        self._connection_status: Dict[str, bool] = {}
        self._socket_aliases: Dict[str, str] = {}

        self.logger.debug(
            "initialized",
            {"socket_dir": str(self.socket_dir)},
        )

    def _get_socket_path(self, container_id: str) -> Path:
        """Get the socket file path for a container."""
        socket_name = self._socket_aliases.get(container_id, container_id)
        return self.socket_dir / socket_name / "kawaflow.sock"

    async def setup_socket(
        self, container_id: str, socket_name: Optional[str] = None
    ) -> None:
        """
        Set up Unix socket for container communication.

        Args:
            container_id: ID of the container to set up socket for

        Raises:
            SocketConnectionError: If socket setup fails
        """
        try:
            self._socket_aliases[container_id] = socket_name or container_id
            socket_path = self._get_socket_path(container_id)
            socket_path.parent.mkdir(parents=True, exist_ok=True)

            # Remove existing socket file if it exists
            if socket_path.exists():
                socket_path.unlink()
                self.logger.debug(
                    "Removed existing socket file",
                    {"container_id": container_id, "socket_path": str(socket_path)},
                )

            # Create Unix socket
            sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            sock.setblocking(True)

            # Bind to socket path
            sock.bind(str(socket_path))
            sock.listen(1)

            # Store connection
            self._connections[container_id] = sock
            self._connection_status[container_id] = True

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
        """Move an existing socket binding to a new container ID key."""
        if existing_id == new_id:
            return

        if existing_id in self._connections:
            self._connections[new_id] = self._connections.pop(existing_id)

        if existing_id in self._connection_status:
            self._connection_status[new_id] = self._connection_status.pop(existing_id)

        socket_name = self._socket_aliases.pop(existing_id, existing_id)
        self._socket_aliases[new_id] = socket_name

        self.logger.debug(
            "Socket key linked",
            {
                "existing_id": existing_id,
                "new_id": new_id,
                "socket_name": socket_name,
            },
        )

    async def cleanup_socket(self, container_id: str) -> None:
        """
        Clean up Unix socket for container.

        Args:
            container_id: ID of the container to clean up socket for
        """
        try:
            # Close socket connection if exists
            if container_id in self._connections:
                sock = self._connections[container_id]
                sock.close()
                del self._connections[container_id]

            # Remove socket file
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

            # Update connection status
            self._connection_status[container_id] = False
            if container_id in self._socket_aliases:
                del self._socket_aliases[container_id]

            self.logger.debug(
                "Socket cleanup completed", {"container_id": container_id}
            )

        except Exception as e:
            self.logger.error(
                e, {"operation": "cleanup_socket", "container_id": container_id}
            )
            # Don't raise exception for cleanup operations

    def is_socket_connected(self, container_id: str) -> bool:
        """
        Check if socket is connected for a container.

        Args:
            container_id: ID of the container to check

        Returns:
            True if socket is connected, False otherwise
        """
        return self._connection_status.get(container_id, False)

    def has_pending_connection(self, container_id: str) -> bool:
        if not self.is_socket_connected(container_id):
            return False

        sock = self._connections.get(container_id)
        if not sock:
            return False

        try:
            ready, _, _ = select.select([sock], [], [], 0)
        except (OSError, ValueError):
            return False

        return bool(ready)

    def _is_stale_client_connection(self, client_sock: socket.socket) -> bool:
        """Check whether an accepted client socket is stale response traffic."""
        try:
            ready, _, _ = select.select([client_sock], [], [], 0.01)
        except (OSError, ValueError):
            return True

        if not ready:
            return False

        try:
            data = client_sock.recv(1, socket.MSG_PEEK)
        except BlockingIOError:
            return False
        except OSError:
            return True

        # Readable with no bytes means peer already closed. Readable with bytes means
        # this is likely a queued container->manager response connection.
        return data == b"" or len(data) > 0

    async def send_message(
        self, container_id: str, message: dict, timeout: int = 30
    ) -> None:
        """
        Send message to container via Unix socket.

        Args:
            container_id: ID of the container to send message to
            message: Message dictionary to send
            timeout: Timeout in seconds for accepting socket connection

        Raises:
            SocketConnectionError: If socket is not connected
            SocketCommunicationError: If message sending fails
        """
        if not self.is_socket_connected(container_id):
            raise SocketConnectionError(
                f"Socket not connected for container {container_id}"
            )

        try:
            # Serialize message to JSON
            message_data = json.dumps(message).encode("utf-8")
            message_length = len(message_data)

            # Get socket connection
            sock = self._connections.get(container_id)
            if not sock:
                raise SocketConnectionError(
                    f"No socket connection for container {container_id}"
                )

            loop = asyncio.get_event_loop()
            deadline = loop.time() + timeout
            length_bytes = message_length.to_bytes(4, byteorder="big")

            retry_count = 0
            while True:
                remaining = deadline - loop.time()
                if remaining <= 0:
                    raise SocketTimeoutError(
                        f"Timeout waiting for container connection {container_id}"
                    )

                try:
                    client_sock, _ = await asyncio.wait_for(
                        loop.run_in_executor(None, sock.accept),
                        timeout=remaining,
                    )
                except asyncio.TimeoutError:
                    raise SocketTimeoutError(
                        f"Timeout waiting for container connection {container_id}"
                    )

                if self._is_stale_client_connection(client_sock):
                    retry_count += 1
                    client_sock.close()
                    self.logger.debug(
                        "Skipping stale socket connection",
                        {
                            "container_id": container_id,
                            "retry_count": retry_count,
                        },
                    )
                    continue

                try:
                    remaining = deadline - loop.time()
                    if remaining <= 0:
                        raise SocketTimeoutError(
                            f"Timeout sending message to container {container_id}"
                        )
                    await asyncio.wait_for(
                        loop.run_in_executor(None, client_sock.sendall, length_bytes),
                        timeout=remaining,
                    )

                    remaining = deadline - loop.time()
                    if remaining <= 0:
                        raise SocketTimeoutError(
                            f"Timeout sending message to container {container_id}"
                        )
                    await asyncio.wait_for(
                        loop.run_in_executor(None, client_sock.sendall, message_data),
                        timeout=remaining,
                    )
                    client_sock.close()
                    break
                except OSError as exc:
                    retry_count += 1
                    client_sock.close()
                    self.logger.debug(
                        "Retrying socket send after socket write error",
                        {
                            "container_id": container_id,
                            "retry_count": retry_count,
                            "error_type": type(exc).__name__,
                        },
                    )
                    continue

            self.logger.communication(container_id, "sent", message)

        except (SocketConnectionError, SocketTimeoutError):
            raise
        except Exception as e:
            self.logger.error(
                e,
                {
                    "operation": "send_message",
                    "container_id": container_id,
                    "message": message,
                    "timeout": timeout,
                },
            )
            raise SocketCommunicationError(
                f"Failed to send message to container {container_id}: {str(e)}"
            )

    async def receive_message(self, container_id: str, timeout: int = 30) -> dict:
        """
        Receive message from container via Unix socket.

        Args:
            container_id: ID of the container to receive message from
            timeout: Timeout in seconds for receiving message

        Returns:
            Received message as dictionary

        Raises:
            SocketConnectionError: If socket is not connected
            SocketTimeoutError: If receive operation times out
            SocketCommunicationError: If message receiving fails
        """
        if not self.is_socket_connected(container_id):
            raise SocketConnectionError(
                f"Socket not connected for container {container_id}"
            )

        try:
            # Get socket connection
            sock = self._connections.get(container_id)
            if not sock:
                raise SocketConnectionError(
                    f"No socket connection for container {container_id}"
                )

            # Accept connection if needed (for server socket)
            try:
                client_sock, _ = await asyncio.wait_for(
                    asyncio.get_event_loop().run_in_executor(None, sock.accept),
                    timeout=timeout,
                )
            except asyncio.TimeoutError:
                raise SocketTimeoutError(
                    f"Timeout waiting for connection from container {container_id}"
                )
            except Exception:
                # If accept fails, assume we're already connected
                client_sock = sock

            # Receive message length first (4 bytes)
            try:
                length_bytes = await asyncio.wait_for(
                    asyncio.get_event_loop().run_in_executor(None, client_sock.recv, 4),
                    timeout=timeout,
                )
            except asyncio.TimeoutError:
                raise SocketTimeoutError(
                    f"Timeout receiving message length from container {container_id}"
                )

            if len(length_bytes) != 4:
                raise SocketCommunicationError(
                    f"Invalid message length received from container {container_id}"
                )

            message_length = int.from_bytes(length_bytes, byteorder="big")

            # Receive message data
            try:
                message_data = await asyncio.wait_for(
                    asyncio.get_event_loop().run_in_executor(
                        None, client_sock.recv, message_length
                    ),
                    timeout=timeout,
                )
            except asyncio.TimeoutError:
                raise SocketTimeoutError(
                    f"Timeout receiving message data from container {container_id}"
                )

            if len(message_data) != message_length:
                raise SocketCommunicationError(
                    f"Incomplete message received from container {container_id}"
                )

            # Deserialize message from JSON
            try:
                message = json.loads(message_data.decode("utf-8"))
            except json.JSONDecodeError as e:
                raise SocketCommunicationError(
                    f"Invalid JSON message from container {container_id}: {str(e)}"
                )

            self.logger.communication(container_id, "received", message)

            return message

        except (SocketTimeoutError, SocketConnectionError, SocketCommunicationError):
            # Re-raise our custom exceptions
            raise
        except Exception as e:
            self.logger.error(
                e,
                {
                    "operation": "receive_message",
                    "container_id": container_id,
                    "timeout": timeout,
                },
            )
            raise SocketCommunicationError(
                f"Failed to receive message from container {container_id}: {str(e)}"
            )

    async def close_all_connections(self) -> None:
        """Close all active socket connections."""
        for container_id in list(self._connections.keys()):
            await self.cleanup_socket(container_id)

        self.logger.debug("All socket connections closed", {})
