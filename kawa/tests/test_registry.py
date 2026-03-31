import pytest
from unittest.mock import MagicMock

from kawa.core import ActorDefinition, EventDefinition
from kawa.registry import Registry


@pytest.fixture
def mock_event_definition():
    event_def = MagicMock(spec=EventDefinition)
    event_def.id = "event1"
    event_def.name = "Test"
    event_def.doc = "A test event"
    return event_def


@pytest.fixture
def mock_actor_definition():
    actor_def = MagicMock(spec=ActorDefinition)
    actor_def.id = "actor1"
    actor_def.name = "TestActor"
    actor_def.doc = "A test actor"
    actor_def.receivs = []
    actor_def.sends = []
    actor_def.min_instances = 1
    actor_def.max_instances = 1
    actor_def.keep_instance = None
    return actor_def


def test_registry_initialization():
    registry = Registry()
    assert registry.actors == {}
    assert registry.events == {}


def test_register_event(mock_event_definition):
    registry = Registry()
    registry.register_event(mock_event_definition)
    assert registry.events[mock_event_definition.id] == mock_event_definition


def test_register_event_idempotent(mock_event_definition):
    registry = Registry()
    registry.register_event(mock_event_definition)
    registry.register_event(mock_event_definition)
    assert len(registry.events) == 1


def test_register_actor(mock_actor_definition):
    registry = Registry()
    registry.register_actor(mock_actor_definition)
    assert registry.actors[mock_actor_definition.id] == mock_actor_definition


def test_register_actor_idempotent(mock_actor_definition):
    registry = Registry()
    registry.register_actor(mock_actor_definition)
    registry.register_actor(mock_actor_definition)
    assert len(registry.actors) == 1


def test_dump_event(mock_event_definition):
    registry = Registry()
    dumped_event = registry._dump_event(mock_event_definition)
    assert dumped_event["id"] == mock_event_definition.id
    assert dumped_event["name"] == mock_event_definition.name
    assert dumped_event["doc"] == mock_event_definition.doc


def test_dump_actor(mock_actor_definition):
    registry = Registry()
    dumped_actor = registry._dump_actor(mock_actor_definition)
    assert dumped_actor["id"] == mock_actor_definition.id
    assert dumped_actor["name"] == mock_actor_definition.name
    assert dumped_actor["doc"] == mock_actor_definition.doc


def test_dump(mock_event_definition, mock_actor_definition):
    registry = Registry()
    registry.register_event(mock_event_definition)
    registry.register_actor(mock_actor_definition)
    dump = registry.dump()
    assert len(dump["events"]) == 1
    assert len(dump["actors"]) == 1
