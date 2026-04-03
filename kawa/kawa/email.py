from typing import Optional, Union

from .main import event


@event
class SendEmail:
    message: str
    recipient: Optional[Union[str, list[str]]] = None
    subject: Optional[str] = None
