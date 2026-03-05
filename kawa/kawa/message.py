from .main import event


@event
class Message:
    """Built-in message event for Flow logs."""

    message: str
