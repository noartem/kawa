from datetime import datetime

from .core import EventFilter
from .main import event


@event
class Cron:
    template: str
    datetime: datetime

    @staticmethod
    def by(template: str):
        return EventFilter(
            Cron, {"template": template}, lambda e: e.template == template
        )
