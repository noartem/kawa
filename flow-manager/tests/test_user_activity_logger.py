from datetime import datetime

import pytest
from unittest.mock import Mock

from system_logger import SystemLogger
from messaging import InMemoryMessaging
from sensivity_filter import SensivityFilter
from user_activity_logger import UserActivityLogger


@pytest.fixture
def logger():
    return Mock(spec=SystemLogger)


@pytest.fixture
def messaging(logger):
    return InMemoryMessaging(logger=logger)


@pytest.fixture
def activity_logger(messaging):
    return UserActivityLogger(messaging, SensivityFilter())


class TestUserActivityLogger:
    @pytest.mark.asyncio
    async def test_container_created(self, activity_logger):
        container_id = "test_container_123"
        name = "test-container"
        image = "nginx:latest"

        await activity_logger.container_created(container_id, name, image)

        event = activity_logger.messaging.published_events[0]
        payload = event["payload"]
        assert payload["type"] == "container_created"
        assert payload["container_id"] == container_id
        assert "created successfully" in payload["message"]
        assert payload["details"]["name"] == name
        assert payload["details"]["image"] == image
        assert payload["details"]["status"] == "created"
        assert "timestamp" in payload

    @pytest.mark.asyncio
    async def test_container_started(self, activity_logger):
        await activity_logger.container_started("cid", "name")
        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "container_started"
        assert payload["details"]["status"] == "running"

    @pytest.mark.asyncio
    async def test_container_stopped(self, activity_logger):
        await activity_logger.container_stopped("cid", "name")
        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "container_stopped"
        assert payload["details"]["status"] == "stopped"

    @pytest.mark.asyncio
    async def test_container_restarted(self, activity_logger):
        await activity_logger.container_restarted("cid", "name")
        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "container_restarted"
        assert payload["details"]["status"] == "running"

    @pytest.mark.asyncio
    async def test_container_updated(self, activity_logger):
        await activity_logger.container_updated("cid", "name")
        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "container_updated"
        assert payload["details"]["status"] == "updated"

    @pytest.mark.asyncio
    async def test_container_deleted(self, activity_logger):
        await activity_logger.container_deleted("cid", "name")
        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "container_deleted"
        assert payload["details"]["status"] == "deleted"

    @pytest.mark.asyncio
    async def test_log_container_message_received(self, activity_logger):
        message_data = {"type": "status", "data": {"health": "ok"}}
        await activity_logger.container_message("cid", message_data, "received")
        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "container_message"
        assert "received from container" in payload["message"]
        assert payload["details"]["direction"] == "received"
        assert payload["details"]["message_type"] == "status"

    @pytest.mark.asyncio
    async def test_log_container_message_sent(self, activity_logger):
        await activity_logger.container_message("cid", {"command": "restart"}, "sent")
        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["details"]["direction"] == "sent"

    @pytest.mark.asyncio
    async def test_log_actor_event(self, activity_logger):
        await activity_logger.actor_event(
            "cid", "EmailActor", "email_sent", {"recipient": "user@example.com"}
        )
        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "actor_event"
        assert payload["details"]["actor"] == "EmailActor"
        assert payload["details"]["event"] == "email_sent"

    @pytest.mark.asyncio
    async def test_log_container_error(self, activity_logger):
        await activity_logger.container_error(
            "cid", "Connection timeout", "start_container"
        )
        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "container_error"
        assert payload["details"]["error"] is True
        assert payload["details"]["operation"] == "start_container"
        assert datetime.fromisoformat(payload["timestamp"])

    @pytest.mark.asyncio
    async def test_log_cron_system_event(self, activity_logger):
        await activity_logger.cron_system_event(
            "cid", dispatch_count=2, timezone_name="UTC"
        )

        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "cron_system_event"
        assert payload["details"]["dispatch_count"] == 2
        assert payload["details"]["timezone"] == "UTC"
        assert payload["details"]["trigger_event"] == "Cron"

    @pytest.mark.asyncio
    async def test_log_actor_invoked(self, activity_logger):
        await activity_logger.actor_invoked(
            "cid",
            "MorningActor",
            "Cron",
            {"template": "0 8 * * *"},
        )

        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "actor_invoked"
        assert payload["details"]["actor"] == "MorningActor"
        assert payload["details"]["trigger_event"] == "Cron"
        assert payload["details"]["event_data"]["template"] == "0 8 * * *"

    @pytest.mark.asyncio
    async def test_log_actor_dispatched(self, activity_logger):
        await activity_logger.actor_dispatched(
            "cid",
            "MorningActor",
            "WakeUp",
            {"trigger_event": "Cron"},
        )

        payload = activity_logger.messaging.published_events[-1]["payload"]
        assert payload["type"] == "actor_dispatched"
        assert payload["details"]["actor"] == "MorningActor"
        assert payload["details"]["dispatched_event"] == "WakeUp"
        assert payload["details"]["event_data"]["trigger_event"] == "Cron"
