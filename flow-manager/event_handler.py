import asyncio
from typing import Any, Awaitable, Dict, Optional

from docker.errors import APIError, ImageNotFound, NotFound

from container_manager import ContainerManager
from models import (
    ContainerConfig,
    ContainerOperationEvent,
    ContainerStatus,
    CreateContainerEvent,
    ErrorResponse,
    GenerateLockEvent,
    SendMessageEvent,
    UpdateContainerEvent,
)
from messaging import Messaging
from socket_communication_handler import (
    SocketCommunicationError,
    SocketCommunicationHandler,
    SocketConnectionError,
    SocketTimeoutError,
)
from system_logger import SystemLogger
from user_activity_logger import UserActivityLogger


class EventHandler:
    def __init__(
        self,
        messaging: Messaging,
        container_manager: ContainerManager,
        socket_handler: SocketCommunicationHandler,
        logger: SystemLogger,
        user_logger: UserActivityLogger,
    ):
        self.messaging = messaging
        self.container_manager = container_manager
        self.socket_handler = socket_handler
        self.logger = logger
        self.user_logger = user_logger

        self.handlers = {
            "create_container": self.handle_create_container,
            "start_container": self.handle_start_container,
            "stop_container": self.handle_stop_container,
            "restart_container": self.handle_restart_container,
            "update_container": self.handle_update_container,
            "delete_container": self.handle_delete_container,
            "send_message": self.handle_send_message,
            "get_container_status": self.handle_get_status,
            "get_container_graph": self.handle_get_container_graph,
            "list_containers": self.handle_list_containers,
            "generate_lock": self.handle_generate_lock,
        }

        self._register_container_callbacks()
        self.logger.debug(
            "initialized",
            {"handlers": list(self.handlers.keys())},
        )

    async def start(self) -> None:
        """Begin consuming commands from messaging backend."""
        await self.messaging.consume_commands(self._dispatch_command)

    async def _dispatch_command(self, payload: Dict[str, Any], message: Any) -> None:
        """Route incoming command payload to the appropriate handler."""
        action = payload.get("action")
        data = payload.get("data", {})
        reply_to = payload.get("reply_to") or getattr(message, "reply_to", None)
        correlation_id = getattr(message, "correlation_id", None) or payload.get(
            "correlation_id"
        )

        if not action or action not in self.handlers:
            await self._emit_error(
                action or "unknown",
                data,
                ValueError(f"Unknown action '{action}'"),
                reply_to,
                correlation_id,
            )
            return

        try:
            self.logger.debug(
                "Handling command",
                {
                    "action": action,
                    "reply_to": reply_to,
                    "correlation_id": correlation_id,
                },
            )
            handler = self.handlers[action]
            response = await handler(data)
            if reply_to:
                await self.messaging.publish_response(
                    action,
                    {"ok": True, "action": action, "data": response},
                    reply_to=reply_to,
                    correlation_id=correlation_id,
                )
        except Exception as exc:
            await self._emit_error(action, data, exc, reply_to, correlation_id)

    async def handle_create_container(self, data: Dict[str, Any]) -> Dict[str, Any]:
        event_data = CreateContainerEvent(**data)

        labels = dict(event_data.labels)
        if event_data.flow_id is not None:
            labels.setdefault("kawaflow.flow_id", str(event_data.flow_id))
        if event_data.flow_run_id is not None:
            labels.setdefault("kawaflow.flow_run_id", str(event_data.flow_run_id))
        if event_data.flow_name:
            labels.setdefault("kawaflow.flow_name", event_data.flow_name)
        if event_data.graph_hash:
            labels.setdefault("kawaflow.graph_hash", event_data.graph_hash)
        if event_data.test_run_id:
            labels.setdefault("kawaflow.test_run_id", event_data.test_run_id)
        config = ContainerConfig(
            image=event_data.image,
            name=event_data.name,
            labels=labels,
            environment=event_data.environment,
            volumes=event_data.volumes,
            ports=event_data.ports,
            command=event_data.command,
            working_dir=event_data.working_dir,
        )

        try:
            container_info = await self.container_manager.create_container(config)
        except Exception:
            raise

        await self.socket_handler.setup_socket(container_info.id, container_info.name)
        await self.messaging.publish_event(
            "container_created",
            {
                "container_id": container_info.id,
                "name": container_info.name,
                "image": container_info.image,
                "flow_id": event_data.flow_id,
                "flow_run_id": event_data.flow_run_id,
                "graph_hash": event_data.graph_hash,
                "actors": event_data.actors,
                "events": event_data.events,
                "test_run_id": event_data.test_run_id,
            },
        )
        self._emit_activity_log_background(
            self.user_logger.container_created(
                container_info.id,
                container_info.name,
                container_info.image,
            ),
            operation="container_created_activity_log",
            container_id=container_info.id,
        )

        return {
            "container_id": container_info.id,
            "name": container_info.name,
            "image": container_info.image,
            "status": container_info.status,
            "socket_path": container_info.socket_path,
            "ports": container_info.ports,
        }

    async def handle_start_container(self, data: Dict[str, Any]) -> Dict[str, Any]:
        event_data = ContainerOperationEvent(**data)
        await self.container_manager.start_container(event_data.container_id)
        containers = await self.container_manager.list_containers()
        container_name = next(
            (c.name for c in containers if c.id == event_data.container_id),
            event_data.container_id,
        )
        await self.user_logger.container_started(
            event_data.container_id, container_name
        )
        return {"container_id": event_data.container_id, "status": "running"}

    async def handle_stop_container(self, data: Dict[str, Any]) -> Dict[str, Any]:
        event_data = ContainerOperationEvent(**data)
        containers = await self.container_manager.list_containers()
        container_name = next(
            (c.name for c in containers if c.id == event_data.container_id),
            event_data.container_id,
        )
        await self.container_manager.stop_container(event_data.container_id)
        await self.user_logger.container_stopped(
            event_data.container_id, container_name
        )
        return {"container_id": event_data.container_id, "status": "stopped"}

    async def handle_restart_container(self, data: Dict[str, Any]) -> Dict[str, Any]:
        event_data = ContainerOperationEvent(**data)
        containers = await self.container_manager.list_containers()
        container_name = next(
            (c.name for c in containers if c.id == event_data.container_id),
            event_data.container_id,
        )
        await self.container_manager.restart_container(event_data.container_id)
        await self.user_logger.container_restarted(
            event_data.container_id, container_name
        )
        return {"container_id": event_data.container_id, "status": "running"}

    async def handle_update_container(self, data: Dict[str, Any]) -> Dict[str, Any]:
        event_data = UpdateContainerEvent(**data)
        containers = await self.container_manager.list_containers()
        container_name = next(
            (c.name for c in containers if c.id == event_data.container_id),
            event_data.container_id,
        )
        updated_container_id = await self.container_manager.update_container(
            event_data.container_id, event_data.code_path
        )
        self.socket_handler.link_socket_key(
            event_data.container_id, updated_container_id
        )
        await self.user_logger.container_updated(updated_container_id, container_name)
        return {
            "container_id": updated_container_id,
            "previous_container_id": event_data.container_id,
            "code_path": event_data.code_path,
            "status": "updated",
        }

    async def handle_delete_container(self, data: Dict[str, Any]) -> Dict[str, Any]:
        event_data = ContainerOperationEvent(**data)
        containers = await self.container_manager.list_containers()
        container_name = next(
            (c.name for c in containers if c.id == event_data.container_id),
            event_data.container_id,
        )
        await self.socket_handler.cleanup_socket(event_data.container_id)
        await self.container_manager.delete_container(event_data.container_id)
        await self.user_logger.container_deleted(
            event_data.container_id, container_name
        )
        return {"container_id": event_data.container_id, "status": "deleted"}

    async def handle_send_message(self, data: Dict[str, Any]) -> Dict[str, Any]:
        event_data = SendMessageEvent(**data)
        await self.socket_handler.send_message(
            event_data.container_id, event_data.message
        )
        await self.user_logger.container_message(
            event_data.container_id, event_data.message, "sent"
        )
        return {
            "container_id": event_data.container_id,
            "message": event_data.message,
            "status": "sent",
        }

    async def handle_get_status(self, data: Dict[str, Any]) -> Dict[str, Any]:
        event_data = ContainerOperationEvent(**data)
        status = await self.container_manager.get_container_status(
            event_data.container_id
        )
        return self._serialize_status(status)

    async def handle_get_container_graph(self, data: Dict[str, Any]) -> Dict[str, Any]:
        event_data = ContainerOperationEvent(**data)
        graph = await self.container_manager.get_container_graph(
            event_data.container_id
        )
        return {"container_id": event_data.container_id, "graph": graph}

    async def handle_list_containers(self, data: Dict[str, Any]) -> Dict[str, Any]:
        containers = await self.container_manager.list_containers()
        return {
            "containers": [
                {
                    "id": c.id,
                    "name": c.name,
                    "status": c.status,
                    "image": c.image,
                    "created": c.created.isoformat(),
                    "socket_path": c.socket_path,
                    "ports": c.ports,
                }
                for c in containers
            ],
            "count": len(containers),
        }

    async def handle_generate_lock(self, data: Dict[str, Any]) -> Dict[str, Any]:
        event_data = GenerateLockEvent(**data)

        try:
            lock_content = await self.container_manager.generate_uv_lock(
                event_data.image, event_data.code
            )
            payload = {
                "flow_id": event_data.flow_id,
                "flow_run_id": event_data.flow_run_id,
                "lock": lock_content,
            }
            await self.messaging.publish_event("lock_generated", payload)
            return payload
        except Exception as exc:
            await self.messaging.publish_event(
                "lock_failed",
                {
                    "flow_id": event_data.flow_id,
                    "flow_run_id": event_data.flow_run_id,
                    "error": str(exc),
                },
            )
            raise

    async def _emit_error(
        self,
        action: str,
        data: Dict[str, Any],
        error: Exception,
        reply_to: Optional[str],
        correlation_id: Optional[str],
    ) -> None:
        """Publish standardized error response."""
        error_type = self._map_error_type(error)
        self.logger.error(
            error,
            {
                "operation": action,
                "data": data,
                "reply_to": reply_to,
                "correlation_id": correlation_id,
            },
        )

        error_response = ErrorResponse(
            error=True,
            error_type=error_type,
            message=str(error),
            details={"operation": action, "data": data},
        )

        if reply_to:
            await self.messaging.publish_response(
                action or "unknown",
                error_response.model_dump(mode="json"),
                reply_to=reply_to,
                correlation_id=correlation_id,
            )

        container_id = data.get("container_id", "unknown")
        await self.user_logger.container_error(
            container_id, str(error), operation=action
        )

    def _emit_activity_log_background(
        self,
        coroutine: Awaitable[None],
        *,
        operation: str,
        container_id: str,
    ) -> None:
        async def _run() -> None:
            try:
                await coroutine
            except Exception as exc:
                self.logger.error(
                    exc,
                    {
                        "operation": operation,
                        "container_id": container_id,
                    },
                )

        asyncio.create_task(_run())

    def _register_container_callbacks(self) -> None:
        """Register callbacks for container monitoring events."""
        self.container_manager.register_status_change_callback(self._on_status_change)
        self.container_manager.register_health_check_callback(
            self._on_health_check_failure
        )
        self.container_manager.register_crash_callback(self._on_container_crash)
        self.container_manager.register_resource_alert_callback(self._on_resource_alert)

    async def _on_status_change(
        self, container_id: str, old_state: Any, new_state: Any
    ) -> None:
        try:
            old_state_value = getattr(old_state, "value", old_state)
            new_state_value = getattr(new_state, "value", new_state)
            metadata = self._get_container_event_metadata(container_id)

            await self.messaging.publish_event(
                "status_changed",
                {
                    "container_id": container_id,
                    **metadata,
                    "old_state": str(old_state_value),
                    "new_state": str(new_state_value),
                    "timestamp": asyncio.get_event_loop().time(),
                },
            )
        except Exception as exc:
            self.logger.error(
                exc,
                {
                    "callback": "_on_status_change",
                    "container_id": container_id,
                },
            )

    def _get_container_event_metadata(self, container_id: str) -> Dict[str, Any]:
        try:
            container = self.container_manager.docker_client.containers.get(
                container_id
            )
        except (NotFound, APIError) as exc:
            self.logger.error(
                exc,
                {
                    "callback": "_get_container_event_metadata",
                    "container_id": container_id,
                },
            )
            return {}

        labels = getattr(container, "labels", None)

        if not isinstance(labels, dict):
            attrs = getattr(container, "attrs", {})
            config = attrs.get("Config", {}) if isinstance(attrs, dict) else {}
            labels = config.get("Labels", {}) if isinstance(config, dict) else {}

        if not isinstance(labels, dict):
            return {}

        metadata: Dict[str, Any] = {}

        flow_id = labels.get("kawaflow.flow_id")
        if flow_id is not None:
            metadata["flow_id"] = int(flow_id) if flow_id.isdigit() else flow_id

        flow_run_id = labels.get("kawaflow.flow_run_id")
        if flow_run_id is not None:
            metadata["flow_run_id"] = (
                int(flow_run_id) if flow_run_id.isdigit() else flow_run_id
            )

        for label_key, payload_key in [
            ("kawaflow.flow_name", "flow_name"),
            ("kawaflow.graph_hash", "graph_hash"),
            ("kawaflow.test_run_id", "test_run_id"),
        ]:
            value = labels.get(label_key)
            if value:
                metadata[payload_key] = value

        return metadata

    async def _on_health_check_failure(self, container_id: str, health: str) -> None:
        try:
            await self.messaging.publish_event(
                "container_health_warning",
                {
                    "container_id": container_id,
                    "health": health,
                    "timestamp": asyncio.get_event_loop().time(),
                },
            )
        except Exception as exc:
            self.logger.error(
                exc,
                {
                    "callback": "_on_health_check_failure",
                    "container_id": container_id,
                },
            )

    async def _on_container_crash(
        self, container_id: str, exit_code: int, crash_details: Dict[str, Any]
    ) -> None:
        try:
            await self.messaging.publish_event(
                "container_crashed",
                {
                    "container_id": container_id,
                    "exit_code": exit_code,
                    "crash_details": crash_details,
                    "timestamp": asyncio.get_event_loop().time(),
                },
            )
        except Exception as exc:
            self.logger.error(
                exc,
                {"callback": "_on_container_crash", "container_id": container_id},
            )

    async def _on_resource_alert(
        self,
        container_id: str,
        resource_type: str,
        current_value: float,
        threshold: float,
        usage_data: Dict[str, Any],
    ) -> None:
        try:
            await self.messaging.publish_event(
                "resource_alert",
                {
                    "container_id": container_id,
                    "resource_type": resource_type,
                    "current_value": current_value,
                    "threshold": threshold,
                    "usage_data": usage_data,
                    "timestamp": asyncio.get_event_loop().time(),
                },
            )
        except Exception as exc:
            self.logger.error(
                exc, {"callback": "_on_resource_alert", "container_id": container_id}
            )

    def _map_error_type(self, error: Exception) -> str:
        """Translate exceptions into error types."""
        if isinstance(error, ImageNotFound):
            return "image_not_found"
        if isinstance(error, NotFound):
            return "container_not_found"
        if isinstance(error, APIError):
            return "docker_api_error"
        if isinstance(
            error,
            (SocketCommunicationError, SocketTimeoutError, SocketConnectionError),
        ):
            return "socket_error"
        return "system_error"

    def _serialize_status(self, status: ContainerStatus) -> Dict[str, Any]:
        """Convert ContainerStatus to plain dict for messaging."""
        return {
            "container_id": status.id,
            "state": status.state.value,
            "health": status.health.value,
            "uptime": str(status.uptime) if status.uptime else None,
            "socket_connected": status.socket_connected,
            "last_communication": (
                status.last_communication.isoformat()
                if status.last_communication
                else None
            ),
            "resource_usage": status.resource_usage,
        }
