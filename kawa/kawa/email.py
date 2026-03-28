from .main import event


@event
class SendEmail:
    message: str
