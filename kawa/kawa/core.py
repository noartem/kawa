import json
import os
import socket
from datetime import timedelta
from typing import (
    Callable,
    Generic,
    Optional,
    Protocol,
    TypeVar,
    Union,
    final,
)

from .utils import (
    get_actor_uuid,
    get_event_uuid,
    untab_string,
)


@final
class NotSupported:
    def __init__(self, event: object):
        self.event = event


@final
class Context:
    def dispatch(self, event: object) -> None:
        from .message import Message

        if isinstance(event, Message):
            self._dispatch_message_event(event.message)

    def _dispatch_message_event(self, message: str) -> None:
        socket_path = os.getenv("KAWAFLOW_SOCKET_PATH", "/run/kawaflow.sock")
        payload = {
            "type": "kawa_message",
            "message": str(message),
        }

        try:
            payload_bytes = json.dumps(payload).encode("utf-8")
            payload_size = len(payload_bytes).to_bytes(4, byteorder="big")

            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
                client.settimeout(1.0)
                client.connect(socket_path)
                client.sendall(payload_size)
                client.sendall(payload_bytes)
        except OSError:
            return


EventFilterT = TypeVar("EventFilterT", bound=object)
EventFilterContext = dict[str, str]


@final
class EventFilter(Generic[EventFilterT]):
    def __init__(
        self,
        event_class: type[EventFilterT],
        context: EventFilterContext,
        filter_function: Callable[[EventFilterT], bool],
    ):
        self.event_class = event_class
        self.context = context
        self.filter_function = filter_function

    def __call__(self, event: EventFilterT) -> bool:
        return self.filter_function(event)


EventClassOrFilter = Union[type[object], type[EventFilter]]


@final
class ActorReceiveEventDefinition:
    def __init__(self, eventClassOrFilter: EventClassOrFilter):
        if isinstance(eventClassOrFilter, EventFilter):
            eventClass = eventClassOrFilter.event_class
            ctx = eventClassOrFilter.context
        else:
            eventClass = eventClassOrFilter
            ctx = {}

        doc = eventClass.__doc__
        if doc is None:
            doc = ""
        doc = untab_string(doc).strip()

        self.id = get_event_uuid(eventClass)
        self.name = eventClass.__name__
        self.doc = doc
        self.ctx = ctx
        self.eventClass = eventClass
        self.eventClassOrFilter = eventClassOrFilter


@final
class ActorSendEventDefinition:
    def __init__(self, eventClass: type[object]):
        doc = eventClass.__doc__
        if doc is None:
            doc = ""
        doc = untab_string(doc).strip()

        if doc == f"{eventClass.__name__}()":
            doc = ""

        self.id = get_event_uuid(eventClass)
        self.name = eventClass.__name__
        self.doc = doc
        self.eventClass = eventClass


class ActorClass(Protocol):
    def __call__(self, ctx: Context, event: object) -> None:
        pass


ActorFuncOrClass = Union[Callable[[Context, object], None], ActorClass]


@final
class ActorDefinition:
    def __init__(
        self,
        actorFuncOrClass: ActorFuncOrClass,
        receivs: Optional[tuple[EventClassOrFilter, ...]] = None,
        sends: Optional[tuple[type[object], ...]] = None,
        min_instances: Optional[int] = None,
        max_instances: Optional[int] = None,
        keep_instance: Optional[timedelta] = None,
    ):
        self.id = get_actor_uuid(actorFuncOrClass)
        if not hasattr(actorFuncOrClass, "__name__"):
            self.name = actorFuncOrClass.__class__.__name__
        else:
            self.name = actorFuncOrClass.__name__
        self.doc = untab_string(actorFuncOrClass.__doc__ or "").strip()
        self.actorFuncOrClass = actorFuncOrClass
        self.receivs = [
            ActorReceiveEventDefinition(receive)
            for receive in (receivs if receivs is not None else tuple())
        ]
        self.sends = [
            ActorSendEventDefinition(send)
            for send in (sends if sends is not None else tuple())
        ]
        self.min_instances = min_instances
        self.max_instances = max_instances
        self.keep_instance = keep_instance


@final
class EventDefinition:
    def __init__(self, eventClass: type[object]):
        doc = eventClass.__doc__
        if doc is None:
            doc = ""
        doc = untab_string(doc).strip()

        self.id = get_event_uuid(eventClass)
        self.name = eventClass.__name__
        self.doc = doc
        self.eventClass = eventClass
