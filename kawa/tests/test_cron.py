from datetime import datetime

from kawa import Cron
from kawa.core import EventFilter


def test_cron_event_creation():
    now = datetime.now()
    event = Cron(template="* * * * *", datetime=now)
    assert event.template == "* * * * *"
    assert event.datetime == now


def test_cron_event_by_filter():
    event_filter = Cron.by("0 0 * * *")
    assert isinstance(event_filter, EventFilter)
    assert event_filter.context == {"template": "0 0 * * *"}

    now = datetime.now()
    matching_event = Cron(template="0 0 * * *", datetime=now)
    non_matching_event = Cron(template="* * * * *", datetime=now)

    assert event_filter(matching_event) is True
    assert event_filter(non_matching_event) is False
