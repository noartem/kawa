from .core import EventFilter
from .main import event


@event
class Webhook:
    slug: str
    payload: object

    @staticmethod
    def by(slug: str):
        return EventFilter(Webhook, {"slug": slug}, lambda e: e.slug == slug)


WebhookEvent = Webhook
