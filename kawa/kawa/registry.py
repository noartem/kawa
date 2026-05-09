from .core import ActorDefinition, EventDefinition


class Registry:
    def __init__(self):
        self.actors = {}
        self.events = {}

    def register_event(self, event_def: EventDefinition):
        if event_def.id in self.events:
            return

        self.events[event_def.id] = event_def

    def register_actor(self, actor_def: ActorDefinition):
        if actor_def.id in self.actors:
            return

        self.actors[actor_def.id] = actor_def

    def _dump_event(self, event: EventDefinition):
        return {
            "id": event.id,
            "name": event.name,
            "doc": event.doc,
        }

    def _dump_actor(self, actor: ActorDefinition):
        return {
            "id": actor.id,
            "name": actor.name,
            "doc": actor.doc,
            "receives": [
                {
                    "id": receive.id,
                    "ctx": receive.ctx,
                }
                for receive in actor.receives
            ],
            "sends": [
                {
                    "id": send.id,
                }
                for send in actor.sends
            ],
            "min_instances": actor.min_instances,
            "max_instances": actor.max_instances,
            "keep_instance": actor.keep_instance,
        }

    def dump(self):
        return {
            "events": [self._dump_event(x) for x in self.events.values()],
            "actors": [self._dump_actor(x) for x in self.actors.values()],
        }
