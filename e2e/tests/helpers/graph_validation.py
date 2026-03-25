import hashlib
from typing import Any, Dict, List


def graph_hash(code: str) -> str:
    normalized_code = (code or "").strip()
    return hashlib.sha256(normalized_code.encode("utf-8")).hexdigest()


def validate_graph_structure(graph: Dict[str, Any]) -> None:
    if not isinstance(graph, dict):
        raise ValueError("graph must be a dict")

    actors = graph.get("actors") or graph.get("nodes") or []
    events = graph.get("events") or graph.get("edges") or []

    if not isinstance(actors, list) or not isinstance(events, list):
        raise ValueError("graph must contain list-like actors/events")

    actor_ids = [item.get("id") for item in actors if isinstance(item, dict)]
    if len(actor_ids) != len(set(actor_ids)):
        raise ValueError("actor ids must be unique")

    event_ids = [item.get("id") for item in events if isinstance(item, dict)]
    if len(event_ids) != len(set(event_ids)):
        raise ValueError("event ids must be unique")


def extract_actor_ids(graph: Dict[str, Any]) -> List[str]:
    actors = graph.get("actors") or graph.get("nodes") or []
    return [
        item["id"]
        for item in actors
        if isinstance(item, dict) and isinstance(item.get("id"), str)
    ]
