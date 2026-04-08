from __future__ import annotations

import json
import time
from dataclasses import asdict, dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any, Callable

import httpx


STORAGE_CHAIN_FLOW_CODE = """
from kawa import actor, event, Context
from kawa.webhook import Webhook


@event
class FirstDone:
    shared_first: str
    first_actor: str
    order_id: int


@event
class SecondDone:
    second_note: str
    first_actor: str
    order_id: int


@actor(receivs=Webhook.by("chain-start"), sends=FirstDone)
def FirstActor(ctx: Context, event: Webhook):
    payload = event.payload if isinstance(event.payload, dict) else {}
    order_id = int(payload.get("order_id", 0))
    seed_message = str(ctx.storage.get("seed.profile.message", "missing"))
    seed_counter = int(ctx.storage.get("seed.counter", 0))

    shared_first = f"{seed_message}|{seed_counter}"

    ctx.storage.set("chain.steps.0.actor", "FirstActor")
    ctx.storage.set("chain.steps.0.from_seed", seed_message)
    ctx.storage.set("chain.steps.0.counter_seen", seed_counter)
    ctx.storage.set("chain.shared.first_value", shared_first)
    ctx.storage.set("chain.shared.webhook.order_id", order_id)
    ctx.storage.set("chain.temp.deleted", "remove-me")

    ctx.dispatch(
        FirstDone(
            shared_first=shared_first,
            first_actor="FirstActor",
            order_id=order_id,
        )
    )


@actor(receivs=FirstDone, sends=SecondDone)
def SecondActor(ctx: Context, event: FirstDone):
    seed_flag = str(ctx.storage.get("seed.flags.0", "missing"))
    second_note = f"{event.shared_first}|{event.order_id}"

    ctx.storage.set("chain.steps.1.actor", "SecondActor")
    ctx.storage.set("chain.steps.1.seed_flag", seed_flag)
    ctx.storage.set("chain.steps.1.seen_first_value", event.shared_first)
    ctx.storage.set("chain.nested.deep.second.note", second_note)
    ctx.storage.delete("chain.temp.deleted")

    ctx.dispatch(
        SecondDone(
            second_note=second_note,
            first_actor=event.first_actor,
            order_id=event.order_id,
        )
    )


@actor(receivs=SecondDone)
def ThirdActor(ctx: Context, event: SecondDone):
    ctx.storage.set("chain.steps.2.actor", "ThirdActor")
    ctx.storage.set("chain.steps.2.seen_first_actor", event.first_actor)
    ctx.storage.set("chain.steps.2.seen_second_note", event.second_note)
    ctx.storage.set("chain.final.status", "completed")
    ctx.storage.set("chain.final.order_id", event.order_id)
    ctx.storage.set("chain.final.shared_first", ctx.storage.get("chain.shared.first_value"))
    ctx.storage.set("chain.final.second_note", event.second_note)
    ctx.storage.set("chain.final.deleted_state", ctx.storage.get("chain.temp.deleted", "missing"))
"""


STORAGE_CHAIN_INITIAL_STORAGE = {
    "seed": {
        "counter": 7,
        "flags": ["seeded"],
        "profile": {
            "message": "hello-storage",
        },
    }
}


def flow_labels(flow_id: int, e2e_settings: Any) -> dict[str, str]:
    labels = {"kawaflow.flow_id": str(flow_id)}
    if e2e_settings.test_run_id:
        labels["kawaflow.test_run_id"] = e2e_settings.test_run_id
    return labels


def wait_for_flow_props(
    ui_client: Any,
    flow_id: int,
    predicate: Callable[[dict[str, Any]], bool],
    timeout: float,
) -> dict[str, Any]:
    deadline = time.time() + timeout
    props: dict[str, Any] = {}

    while time.time() < deadline:
        props = ui_client.flow_show_props(flow_id)
        if predicate(props):
            return props
        time.sleep(1)

    return props


def wait_for_log_entries(
    ui_client: Any,
    flow_id: int,
    predicate: Callable[[list[dict[str, Any]]], bool],
    timeout: float,
) -> list[dict[str, Any]]:
    deadline = time.time() + timeout
    entries: list[dict[str, Any]] = []

    while time.time() < deadline:
        payload = ui_client.flow_logs(flow_id)
        entries = payload.get("data", {}).get("data", [])
        if predicate(entries):
            return entries
        time.sleep(1)

    return entries


def runtime_event_contexts(entries: list[dict[str, Any]]) -> list[dict[str, Any]]:
    contexts = [
        context
        for entry in entries
        if entry.get("message") == "Event: flow_runtime_event"
        if isinstance(context := entry.get("context"), dict)
    ]

    return sorted(
        contexts,
        key=lambda context: int(context.get("seq") or 0),
    )


def _chain_markers(contexts: list[dict[str, Any]]) -> list[tuple[str, str, str | None]]:
    markers: list[tuple[str, str, str | None]] = []

    for context in contexts:
        kind = str(context.get("kind") or "")
        actor = str(context.get("actor") or "")

        if kind == "actor_invoked" and actor in {
            "FirstActor",
            "SecondActor",
            "ThirdActor",
        }:
            markers.append((kind, actor, None))
            continue

        if kind == "event_dispatched" and actor == "FirstActor":
            markers.append((kind, actor, str(context.get("event") or "")))
            continue

        if kind == "event_dispatched" and actor == "SecondActor":
            markers.append((kind, actor, str(context.get("event") or "")))

    return markers


def has_storage_chain_sequence(contexts: list[dict[str, Any]]) -> bool:
    expected = [
        ("actor_invoked", "FirstActor", None),
        ("event_dispatched", "FirstActor", "FirstDone"),
        ("actor_invoked", "SecondActor", None),
        ("event_dispatched", "SecondActor", "SecondDone"),
        ("actor_invoked", "ThirdActor", None),
    ]
    markers = _chain_markers(contexts)
    index = 0

    for marker in markers:
        if marker == expected[index]:
            index += 1
            if index == len(expected):
                return True

    return False


def wait_for_storage_chain_contexts(
    ui_client: Any,
    flow_id: int,
    timeout: float,
) -> list[dict[str, Any]]:
    entries = wait_for_log_entries(
        ui_client,
        flow_id,
        lambda payload: has_storage_chain_sequence(runtime_event_contexts(payload)),
        timeout,
    )
    return runtime_event_contexts(entries)


def development_storage(props: dict[str, Any]) -> dict[str, Any]:
    storage = props.get("storage")
    if not isinstance(storage, dict):
        return {}

    development = storage.get("development")
    return development if isinstance(development, dict) else {}


def has_final_storage_snapshot(
    storage: dict[str, Any],
    *,
    order_id: int,
) -> bool:
    final = storage.get("chain", {}).get("final", {})
    steps = storage.get("chain", {}).get("steps", [])

    return (
        final.get("status") == "completed"
        and final.get("order_id") == order_id
        and final.get("deleted_state") == "missing"
        and isinstance(steps, list)
        and len(steps) >= 3
    )


def wait_for_persisted_development_storage(
    ui_client: Any,
    flow_id: int,
    *,
    order_id: int,
    timeout: float,
) -> dict[str, Any]:
    props = wait_for_flow_props(
        ui_client,
        flow_id,
        lambda payload: has_final_storage_snapshot(
            development_storage(payload),
            order_id=order_id,
        ),
        timeout,
    )
    return development_storage(props)


def wait_for_development_webhook_url(
    ui_client: Any,
    flow_id: int,
    *,
    slug: str,
    timeout: float,
) -> str:
    props = wait_for_flow_props(
        ui_client,
        flow_id,
        lambda payload: resolve_webhook_url(payload, slug=slug) is not None,
        timeout,
    )
    webhook_url = resolve_webhook_url(props, slug=slug)
    if webhook_url is None:
        raise AssertionError("Timed out waiting for development webhook URL")

    return webhook_url


def wait_for_development_graph(
    ui_client: Any,
    flow_id: int,
    *,
    timeout: float,
) -> dict[str, Any]:
    props = wait_for_flow_props(
        ui_client,
        flow_id,
        has_development_graph,
        timeout,
    )

    if not has_development_graph(props):
        raise AssertionError("Timed out waiting for development graph visibility")

    return props


def has_development_graph(props: dict[str, Any]) -> bool:
    development_run = (
        props.get("lastDevelopmentDeployment")
        or props.get("last_development_deployment")
        or props.get("developmentRun")
        or props.get("development_run")
    )
    if not isinstance(development_run, dict):
        return False

    graph = development_run.get("graph")
    return isinstance(graph, dict) and (
        bool(graph.get("nodes")) or bool(graph.get("edges"))
    )


def resolve_webhook_url(props: dict[str, Any], *, slug: str) -> str | None:
    endpoints = props.get("webhookEndpoints")
    if not isinstance(endpoints, list):
        return None

    for endpoint in endpoints:
        if not isinstance(endpoint, dict):
            continue
        if endpoint.get("slug") != slug:
            continue
        development_url = endpoint.get("development_url")
        if isinstance(development_url, str) and development_url != "":
            return development_url

    return None


@dataclass(frozen=True)
class StorageChainBudget:
    run_request_seconds: float
    request_to_container_id_seconds: float
    request_to_webhook_ready_seconds: float
    webhook_request_seconds: float
    webhook_to_final_runtime_event_seconds: float
    webhook_to_storage_persisted_seconds: float


@dataclass(frozen=True)
class StorageChainMeasurement:
    flow_id: int
    container_id: str
    artifact_label: str
    run_request_started_at: str
    container_id_visible_at: str
    webhook_ready_at: str
    webhook_request_started_at: str
    webhook_response_received_at: str
    final_runtime_event_at: str
    storage_persisted_at: str
    run_request_seconds: float
    request_to_container_id_seconds: float
    request_to_webhook_ready_seconds: float
    webhook_request_seconds: float
    webhook_to_final_runtime_event_seconds: float
    webhook_to_storage_persisted_seconds: float

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def _utc_now_iso() -> str:
    return datetime.now(UTC).isoformat()


def load_storage_chain_budget(file_path: str | Path) -> StorageChainBudget:
    payload = json.loads(Path(file_path).read_text(encoding="utf-8"))
    return StorageChainBudget(**payload["storage_chain"]["budgets"])


def write_storage_chain_artifact(
    measurement: StorageChainMeasurement,
    artifact_dir: str | Path,
) -> Path:
    directory = Path(artifact_dir)
    directory.mkdir(parents=True, exist_ok=True)
    timestamp = datetime.now(UTC).strftime("%Y%m%dT%H%M%SZ")
    path = directory / f"storage-chain-{measurement.artifact_label}-{timestamp}.json"
    path.write_text(json.dumps(measurement.to_dict(), indent=2), encoding="utf-8")
    return path


def assert_storage_chain_budget(
    measurement: StorageChainMeasurement,
    budget: StorageChainBudget,
) -> None:
    failures: list[str] = []

    checks = [
        (
            "run_request_seconds",
            measurement.run_request_seconds,
            budget.run_request_seconds,
        ),
        (
            "request_to_container_id_seconds",
            measurement.request_to_container_id_seconds,
            budget.request_to_container_id_seconds,
        ),
        (
            "request_to_webhook_ready_seconds",
            measurement.request_to_webhook_ready_seconds,
            budget.request_to_webhook_ready_seconds,
        ),
        (
            "webhook_request_seconds",
            measurement.webhook_request_seconds,
            budget.webhook_request_seconds,
        ),
        (
            "webhook_to_final_runtime_event_seconds",
            measurement.webhook_to_final_runtime_event_seconds,
            budget.webhook_to_final_runtime_event_seconds,
        ),
        (
            "webhook_to_storage_persisted_seconds",
            measurement.webhook_to_storage_persisted_seconds,
            budget.webhook_to_storage_persisted_seconds,
        ),
    ]

    for metric_name, actual, limit in checks:
        if actual > limit:
            failures.append(
                f"{metric_name} exceeded budget: {actual:.3f}s > {limit:.3f}s"
            )

    if failures:
        raise AssertionError("\n".join(failures))


def measure_storage_chain(
    ui_client: Any,
    e2e_settings: Any,
    flow_id: int,
    *,
    slug: str,
    order_id: int,
) -> StorageChainMeasurement:
    run_request_started_at = _utc_now_iso()
    run_request_started = time.perf_counter()
    ui_client.run_flow(flow_id)
    run_request_completed = time.perf_counter()

    container_id = ui_client.wait_for_container_id(
        flow_id,
        timeout=e2e_settings.container_timeout,
    )
    if not container_id:
        raise AssertionError("Timed out waiting for container id in UI")
    container_id_visible_at = _utc_now_iso()
    container_id_visible = time.perf_counter()

    wait_for_development_webhook_url(
        ui_client,
        flow_id,
        slug=slug,
        timeout=e2e_settings.graph_timeout,
    )
    webhook_ready_at = _utc_now_iso()
    webhook_ready = time.perf_counter()

    webhook_url = wait_for_development_webhook_url(
        ui_client,
        flow_id,
        slug=slug,
        timeout=e2e_settings.graph_timeout,
    )
    webhook_response, webhook_request_started_at, webhook_request_started = (
        wait_for_accepted_webhook_dispatch(
            ui_client,
            webhook_url,
            {"order_id": order_id},
            timeout=e2e_settings.container_timeout,
        )
    )
    webhook_response_received_at = _utc_now_iso()
    webhook_response_received = time.perf_counter()

    wait_for_storage_chain_contexts(
        ui_client,
        flow_id,
        timeout=max(e2e_settings.container_timeout, 45),
    )
    final_runtime_event_at = _utc_now_iso()
    final_runtime_event = time.perf_counter()

    wait_for_persisted_development_storage(
        ui_client,
        flow_id,
        order_id=order_id,
        timeout=max(e2e_settings.container_timeout, 45),
    )
    storage_persisted_at = _utc_now_iso()
    storage_persisted = time.perf_counter()

    return StorageChainMeasurement(
        flow_id=flow_id,
        container_id=container_id,
        artifact_label=f"flow-{flow_id}",
        run_request_started_at=run_request_started_at,
        container_id_visible_at=container_id_visible_at,
        webhook_ready_at=webhook_ready_at,
        webhook_request_started_at=webhook_request_started_at,
        webhook_response_received_at=webhook_response_received_at,
        final_runtime_event_at=final_runtime_event_at,
        storage_persisted_at=storage_persisted_at,
        run_request_seconds=run_request_completed - run_request_started,
        request_to_container_id_seconds=container_id_visible - run_request_started,
        request_to_webhook_ready_seconds=webhook_ready - run_request_started,
        webhook_request_seconds=webhook_response_received - webhook_request_started,
        webhook_to_final_runtime_event_seconds=final_runtime_event
        - webhook_request_started,
        webhook_to_storage_persisted_seconds=storage_persisted
        - webhook_request_started,
    )


def wait_for_accepted_webhook_dispatch(
    ui_client: Any,
    webhook_url: str,
    payload: dict[str, Any],
    *,
    timeout: float,
) -> tuple[httpx.Response, str, float]:
    deadline = time.time() + timeout
    latest_response: httpx.Response | None = None

    while time.time() < deadline:
        request_started_at = _utc_now_iso()
        request_started = time.perf_counter()
        response = ui_client.dispatch_webhook_raw(webhook_url, payload)
        latest_response = response

        if response.status_code == 200:
            return response, request_started_at, request_started

        if response.status_code != 503:
            response.raise_for_status()

        time.sleep(1)

    if latest_response is None:
        raise AssertionError("Timed out waiting for webhook dispatch readiness")

    latest_response.raise_for_status()
    return latest_response, _utc_now_iso(), time.perf_counter()
