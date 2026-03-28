from kawa.core import EventFilter
from kawa.webhook import Webhook, WebhookEvent


def test_webhook_event_creation():
    event = Webhook(slug="my-webhook", payload={"status": "ok"})

    assert event.slug == "my-webhook"
    assert event.payload == {"status": "ok"}


def test_webhook_event_by_filter():
    event_filter = Webhook.by("orders")

    assert isinstance(event_filter, EventFilter)
    assert event_filter.context == {"slug": "orders"}

    matching_event = Webhook(slug="orders", payload={"id": 1})
    non_matching_event = Webhook(slug="users", payload={"id": 2})

    assert event_filter(matching_event) is True
    assert event_filter(non_matching_event) is False


def test_webhook_event_alias_points_to_same_type():
    assert WebhookEvent is Webhook
