from datetime import timedelta
from unittest.mock import MagicMock, patch

from kawa import Message
from kawa.core import (
    Context,
    EventFilter,
    ActorReceiveEventDefinition,
    ActorSendEventDefinition,
    ActorDefinition,
    EventDefinition,
    NotSupported,
)
from kawa.main import actor, event


@event
class My:
    """This is a test event."""

    pass


@event
class Another:
    pass


@actor(receivs=(My,), sends=(Another,))
def my_actor(ctx: Context, event: My):
    """This is a test actor."""
    ctx.dispatch(Another())


class MyActorClass:
    """This is a test actor class."""

    def __call__(self, ctx: Context, event: My):
        ctx.dispatch(Another())


def test_not_supported_event():
    event = My()
    not_supported = NotSupported(event)
    assert not_supported.event == event


def test_context_dispatch():
    with patch.object(Context, "dispatch", new_callable=MagicMock) as mock_dispatch:
        ctx = Context()
        event = My()
        ctx.dispatch(event)
        mock_dispatch.assert_called_once_with(event)


def test_context_dispatch_message_event_to_socket():
    with patch("kawa.core.socket.socket") as mock_socket:
        client = MagicMock()
        mock_socket.return_value.__enter__.return_value = client

        ctx = Context()
        message = Message("hello")
        ctx.dispatch(message)

    client.connect.assert_called_once_with("/run/kawaflow.sock")
    assert client.sendall.call_count == 2


def test_context_dispatch_message_event_ignores_socket_errors():
    with patch("kawa.core.socket.socket") as mock_socket:
        client = MagicMock()
        client.connect.side_effect = OSError("socket unavailable")
        mock_socket.return_value.__enter__.return_value = client

        ctx = Context()
        message = Message("hello")
        ctx.dispatch(message)


def test_event_filter():
    event = My()
    filter_func = MagicMock(return_value=True)
    event_filter = EventFilter(My, {"key": "value"}, filter_func)
    assert event_filter(event)
    filter_func.assert_called_once_with(event)


def test_actor_receive_event_definition():
    definition = ActorReceiveEventDefinition(My)
    assert definition.name == "My"
    assert definition.doc == "This is a test event."
    assert definition.ctx == {}


def test_actor_send_event_definition():
    definition = ActorSendEventDefinition(Another)
    assert definition.name == "Another"
    assert definition.doc == ""


def test__actor_definition_from_function():
    definition = ActorDefinition(
        my_actor,
        receivs=(My,),
        sends=(Another,),
        min_instances=1,
        max_instances=5,
        keep_instance=timedelta(minutes=10),
    )
    assert definition.name == "my_actor"
    assert definition.doc == "This is a test actor."
    assert len(definition.receivs) == 1
    assert len(definition.sends) == 1
    assert definition.min_instances == 1
    assert definition.max_instances == 5
    assert definition.keep_instance == timedelta(minutes=10)


def test_actor_definition_from_class():
    actor_instance = MyActorClass()
    definition = ActorDefinition(
        actor_instance,
        receivs=(My,),
        sends=(Another,),
    )
    assert definition.name == "MyActorClass"
    assert definition.doc == "This is a test actor class."


def test_event_definition():
    definition = EventDefinition(My)
    assert definition.name == "My"
    assert definition.doc == "This is a test event."
