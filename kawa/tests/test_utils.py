from datetime import timedelta
from uuid import UUID

from kawa.utils import (
    get_actor_uuid,
    get_event_uuid,
    untab_string,
    get_object_key,
    TimedeltaEncoder,
    json_encode,
    json_decode,
)


def test_get_actor_uuid():
    def my_actor():
        pass

    assert get_actor_uuid(my_actor) is not None
    assert isinstance(get_actor_uuid(my_actor), UUID)


def test_get_event_uuid():
    class My:
        pass

    assert get_event_uuid(My) is not None
    assert isinstance(get_event_uuid(My), UUID)


def test_untab_string():
    assert untab_string("    hello") == "hello"
    assert untab_string("  hello") == "hello"
    assert untab_string("\thello") == "hello"
    assert untab_string("    hello\n  world") == "hello\nworld"


def test_get_object_key():
    def my_actor():
        pass

    key = get_object_key(my_actor)
    assert isinstance(key, str)
    assert "test_utils.py::my_actor" in key


def test_timedelta_encoder():
    encoder = TimedeltaEncoder()
    delta = timedelta(seconds=10)
    assert encoder.default(delta) == 10


def test_uuid_encoder():
    encoder = TimedeltaEncoder()
    uuid_obj = UUID("12345678-1234-5678-1234-567812345678")
    assert encoder.default(uuid_obj) == "12345678-1234-5678-1234-567812345678"


def test_json_encode_decode():
    data = {
        "delta": timedelta(minutes=1),
        "uuid": UUID("12345678-1234-5678-1234-567812345678"),
        "other": "value",
    }
    encoded = json_encode(data)
    decoded = json_decode(encoded)

    assert decoded["delta"] == 60
    assert decoded["uuid"] == "12345678-1234-5678-1234-567812345678"
    assert decoded["other"] == "value"
