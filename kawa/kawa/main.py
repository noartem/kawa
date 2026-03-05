from datetime import timedelta
from typing import Optional, Union
from dataclasses import dataclass

from .core import ActorDefinition, EventDefinition, EventClassOrFilter, ActorFuncOrClass
from .registry import Registry


registry = Registry()


def event(cls):
    registry.register_event(EventDefinition(cls))
    return dataclass(cls)


def actor(
    receivs: Optional[Union[tuple[EventClassOrFilter, ...], EventClassOrFilter]] = None,
    sends: Optional[Union[tuple[type[object], ...], type[object]]] = None,
    min_instances: Optional[int] = None,
    max_instances: Optional[int] = None,
    keep_instance: Optional[timedelta] = None,
):
    normalized_receivs: tuple[EventClassOrFilter, ...] = ()
    if receivs is not None:
        if isinstance(receivs, tuple):
            normalized_receivs = receivs
        else:
            normalized_receivs = (receivs,)

    normalized_sends: tuple[type[object], ...] = ()
    if sends is not None:
        if isinstance(sends, tuple):
            normalized_sends = sends
        else:
            normalized_sends = (sends,)

    def decorator(actorFuncOrClass: ActorFuncOrClass):
        registry.register_actor(
            ActorDefinition(
                actorFuncOrClass=actorFuncOrClass,
                receivs=normalized_receivs,
                sends=normalized_sends,
                min_instances=min_instances,
                max_instances=max_instances,
                keep_instance=keep_instance,
            )
        )

        return actorFuncOrClass

    return decorator
