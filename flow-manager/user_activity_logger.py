from datetime import datetime, timezone
from typing import Any, Dict, Optional

from sensivity_filter import SensivityFilter


class UserActivityLogger:
    def __init__(
        self, messaging: Any, sensivity_filter: Optional[SensivityFilter] = None
    ):
        self.messaging = messaging
        self.sensivity_filter = sensivity_filter or SensivityFilter()

    async def container_created(
        self, container_id: str, name: str, image: Optional[str] = None
    ) -> None:
        message = f"Container '{name}' created successfully"
        details = {"container_id": container_id, "name": name, "status": "created"}
        if image:
            details["image"] = image

        await self._emit_activity_log(
            "container_created", container_id, message, details
        )

    async def container_started(self, container_id: str, name: str) -> None:
        message = f"Container '{name}' started and is now running"
        details = {"container_id": container_id, "name": name, "status": "running"}
        await self._emit_activity_log(
            "container_started", container_id, message, details
        )

    async def container_stopped(self, container_id: str, name: str) -> None:
        message = f"Container '{name}' stopped successfully"
        details = {"container_id": container_id, "name": name, "status": "stopped"}
        await self._emit_activity_log(
            "container_stopped", container_id, message, details
        )

    async def container_restarted(self, container_id: str, name: str) -> None:
        message = f"Container '{name}' restarted successfully"
        details = {"container_id": container_id, "name": name, "status": "running"}
        await self._emit_activity_log(
            "container_restarted", container_id, message, details
        )

    async def container_updated(self, container_id: str, name: str) -> None:
        message = f"Container '{name}' updated with new code"
        details = {"container_id": container_id, "name": name, "status": "updated"}
        await self._emit_activity_log(
            "container_updated", container_id, message, details
        )

    async def container_deleted(self, container_id: str, name: str) -> None:
        message = f"Container '{name}' deleted and resources cleaned up"
        details = {"container_id": container_id, "name": name, "status": "deleted"}
        await self._emit_activity_log(
            "container_deleted", container_id, message, details
        )

    async def container_message(
        self,
        container_id: str,
        message_data: Dict[str, Any],
        direction: str = "received",
    ) -> None:
        filtered_message = self.sensivity_filter(message_data)

        action = "sent to" if direction == "sent" else "received from"
        message = f"Message {action} container"

        message_type = "unknown"
        if isinstance(message_data, dict):
            message_type = message_data.get(
                "type", message_data.get("command", "unknown")
            )

        details = {
            "container_id": container_id,
            "direction": direction,
            "message_type": message_type,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }

        if not self.sensivity_filter.check_data(message_data):
            details["message_preview"] = (
                str(filtered_message)[:100] + "..."
                if len(str(filtered_message)) > 100
                else str(filtered_message)
            )

        await self._emit_activity_log(
            "container_message", container_id, message, details
        )

    async def actor_event(
        self,
        container_id: str,
        actor: str,
        event: str,
        event_data: Optional[Dict[str, Any]] = None,
    ) -> None:
        message = f"Actor '{actor}' triggered event '{event}'"
        details = {
            "container_id": container_id,
            "actor": actor,
            "event": event,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }

        if event_data:
            details["event_data"] = self.sensivity_filter(event_data)

        await self._emit_activity_log("actor_event", container_id, message, details)

    async def cron_system_event(
        self,
        container_id: str,
        dispatch_count: int,
        timezone_name: str,
    ) -> None:
        message = "System cron event triggered"
        details = {
            "container_id": container_id,
            "dispatch_count": dispatch_count,
            "timezone": timezone_name,
            "trigger_event": "Cron",
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }

        await self._emit_activity_log(
            "cron_system_event",
            container_id,
            message,
            details,
        )

    async def actor_invoked(
        self,
        container_id: str,
        actor: str,
        trigger_event: str,
        event_data: Optional[Dict[str, Any]] = None,
    ) -> None:
        message = f"Actor '{actor}' invoked by event '{trigger_event}'"
        details = {
            "container_id": container_id,
            "actor": actor,
            "trigger_event": trigger_event,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }

        if event_data:
            details["event_data"] = self.sensivity_filter(event_data)

        await self._emit_activity_log("actor_invoked", container_id, message, details)

    async def actor_dispatched(
        self,
        container_id: str,
        actor: str,
        dispatched_event: str,
        event_data: Optional[Dict[str, Any]] = None,
    ) -> None:
        message = f"Actor '{actor}' dispatched event '{dispatched_event}'"
        details = {
            "container_id": container_id,
            "actor": actor,
            "dispatched_event": dispatched_event,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }

        if event_data:
            details["event_data"] = self.sensivity_filter(event_data)

        await self._emit_activity_log(
            "actor_dispatched",
            container_id,
            message,
            details,
        )

    async def container_error(
        self, container_id: str, error_message: str, operation: Optional[str] = None
    ) -> None:
        if operation:
            message = f"Error during {operation}: {error_message}"
        else:
            message = f"Container error: {error_message}"

        details = {
            "container_id": container_id,
            "error": True,
            "operation": operation,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }

        await self._emit_activity_log("container_error", container_id, message, details)

    async def user_activity(
        self,
        activity_type: str,
        container_id: str,
        message: str,
        details: Optional[Dict[str, Any]] = None,
    ) -> None:
        await self._emit_activity_log(
            activity_type, container_id, message, self.sensivity_filter(details)
        )

    async def _emit_activity_log(
        self,
        activity_type: str,
        container_id: str,
        message: str,
        details: Dict[str, Any],
    ) -> None:
        await self.messaging.publish_event(
            "activity_log",
            {
                "type": activity_type,
                "container_id": container_id,
                "message": message,
                "details": details,
                "timestamp": datetime.now(timezone.utc).isoformat(),
            },
            routing_key="event.activity",
        )
