from kawa.core import ActorDefinition
from kawa.registry import Registry
from kawa.runtime import app as runtime_app


class LoopEvent:
    pass


def test_process_pending_events_stops_infinite_event_cycles(monkeypatch):
    emitted_events = []

    def append_runtime_event(event: dict) -> None:
        emitted_events.append(event)

    monkeypatch.setattr(runtime_app, "append_runtime_event", append_runtime_event)

    def loop_actor(ctx, event) -> None:
        ctx.dispatch(LoopEvent())

    registry = Registry()
    registry.register_actor(
        ActorDefinition(loop_actor, receives=(LoopEvent,), sends=(LoopEvent,))
    )

    pending_events = [LoopEvent()]
    runtime_app.process_pending_events(pending_events, registry)

    assert pending_events == []
    assert (
        len([event for event in emitted_events if event["kind"] == "actor_invoked"])
        == runtime_app.EVENT_PROCESSING_STEP_LIMIT
    )
    assert (
        len(
            [event for event in emitted_events if event["kind"] == "event_dispatched"]
        )
        == runtime_app.EVENT_PROCESSING_STEP_LIMIT
    )
    assert emitted_events[-1] == {
        "kind": "runtime_error",
        "actor": "runtime",
        "trigger_event": "LoopEvent",
        "event": "LoopEvent",
        "payload": {
            "error": "event processing stopped after 128 steps to prevent infinite recursion"
        },
    }
