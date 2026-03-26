from __future__ import annotations

import json
import time
from dataclasses import asdict, dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any, Callable


def _utc_now_iso() -> str:
    return datetime.now(UTC).isoformat()


def _wait_for_log_entries(
    ui_client: Any,
    flow_id: int,
    predicate: Callable[[list[dict[str, Any]]], bool],
    timeout: float,
) -> list[dict[str, Any]]:
    deadline = time.time() + timeout
    payload: dict[str, Any] = {"data": {"data": []}}

    while time.time() < deadline:
        payload = ui_client.flow_logs(flow_id)
        entries = payload.get("data", {}).get("data", [])
        if predicate(entries):
            return entries
        time.sleep(1)

    return payload.get("data", {}).get("data", [])


def _runtime_event_contexts(entries: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return [
        context
        for entry in entries
        if entry.get("message") == "Event: flow_runtime_event"
        if isinstance(context := entry.get("context"), dict)
    ]


@dataclass(frozen=True)
class StartupBudget:
    run_request_seconds: float
    request_to_container_running_seconds: float
    request_to_container_id_seconds: float
    request_to_graph_visible_seconds: float
    request_to_first_runtime_event_seconds: float


@dataclass(frozen=True)
class StartupMeasurement:
    flow_id: int
    container_id: str
    artifact_label: str
    run_request_started_at: str
    http_redirect_received_at: str
    container_running_at: str
    container_id_visible_at: str
    graph_visible_at: str
    first_runtime_event_at: str
    run_request_seconds: float
    request_to_container_running_seconds: float
    request_to_container_id_seconds: float
    request_to_graph_visible_seconds: float
    request_to_first_runtime_event_seconds: float

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def load_startup_budget(file_path: str | Path) -> StartupBudget:
    payload = json.loads(Path(file_path).read_text(encoding="utf-8"))
    return StartupBudget(**payload["cron_startup"]["budgets"])


def write_startup_artifact(
    measurement: StartupMeasurement,
    artifact_dir: str | Path,
) -> Path:
    directory = Path(artifact_dir)
    directory.mkdir(parents=True, exist_ok=True)
    timestamp = datetime.now(UTC).strftime("%Y%m%dT%H%M%SZ")
    path = directory / f"startup-{measurement.artifact_label}-{timestamp}.json"
    path.write_text(json.dumps(measurement.to_dict(), indent=2), encoding="utf-8")
    return path


def assert_startup_budget(
    measurement: StartupMeasurement,
    budget: StartupBudget,
) -> None:
    failures: list[str] = []

    checks = [
        (
            "run_request_seconds",
            measurement.run_request_seconds,
            budget.run_request_seconds,
        ),
        (
            "request_to_container_running_seconds",
            measurement.request_to_container_running_seconds,
            budget.request_to_container_running_seconds,
        ),
        (
            "request_to_container_id_seconds",
            measurement.request_to_container_id_seconds,
            budget.request_to_container_id_seconds,
        ),
        (
            "request_to_graph_visible_seconds",
            measurement.request_to_graph_visible_seconds,
            budget.request_to_graph_visible_seconds,
        ),
        (
            "request_to_first_runtime_event_seconds",
            measurement.request_to_first_runtime_event_seconds,
            budget.request_to_first_runtime_event_seconds,
        ),
    ]

    for metric_name, actual, limit in checks:
        if actual > limit:
            failures.append(
                f"{metric_name} exceeded budget: {actual:.3f}s > {limit:.3f}s"
            )

    if failures:
        raise AssertionError("\n".join(failures))


def measure_cron_startup(
    ui_client: Any,
    docker_observer: Any,
    e2e_settings: Any,
    flow_id: int,
) -> StartupMeasurement:
    labels = {"kawaflow.flow_id": str(flow_id)}
    if e2e_settings.test_run_id:
        labels["kawaflow.test_run_id"] = e2e_settings.test_run_id

    run_request_started_at = _utc_now_iso()
    run_request_started = time.perf_counter()
    ui_client.run_flow(flow_id)
    http_redirect_received_at = _utc_now_iso()
    http_redirect_received = time.perf_counter()

    container = docker_observer.wait_for_container(
        labels, e2e_settings.container_timeout
    )
    docker_observer.wait_for_status(
        container,
        "running",
        e2e_settings.container_timeout,
    )
    container_running_at = _utc_now_iso()
    container_running = time.perf_counter()

    container_id = ui_client.wait_for_container_id(
        flow_id,
        timeout=e2e_settings.container_timeout,
    )
    if not container_id:
        raise AssertionError("Timed out waiting for container id in UI")
    container_id_visible_at = _utc_now_iso()
    container_id_visible = time.perf_counter()

    graph_deadline = time.time() + e2e_settings.graph_timeout
    graph_visible = None
    graph_visible_at = None

    while time.time() < graph_deadline:
        props = ui_client.flow_show_props(flow_id)
        development_run = (
            props.get("lastDevelopmentDeployment")
            or props.get("last_development_deployment")
            or props.get("developmentRun")
            or props.get("development_run")
        )
        graph = (
            development_run.get("graph") if isinstance(development_run, dict) else None
        )
        if isinstance(graph, dict) and (
            bool(graph.get("nodes")) or bool(graph.get("edges"))
        ):
            graph_visible_at = _utc_now_iso()
            graph_visible = time.perf_counter()
            break
        time.sleep(1)

    if graph_visible_at is None or graph_visible is None:
        raise AssertionError("Timed out waiting for graph visibility in UI")

    runtime_event_timeout = max(e2e_settings.container_timeout, 70)
    contexts = _runtime_event_contexts(
        _wait_for_log_entries(
            ui_client,
            flow_id,
            lambda entries: bool(_runtime_event_contexts(entries)),
            runtime_event_timeout,
        )
    )
    if not contexts:
        raise AssertionError("Timed out waiting for first runtime event")
    first_runtime_event_at = _utc_now_iso()
    first_runtime_event = time.perf_counter()

    return StartupMeasurement(
        flow_id=flow_id,
        container_id=container_id,
        artifact_label=f"flow-{flow_id}",
        run_request_started_at=run_request_started_at,
        http_redirect_received_at=http_redirect_received_at,
        container_running_at=container_running_at,
        container_id_visible_at=container_id_visible_at,
        graph_visible_at=graph_visible_at,
        first_runtime_event_at=first_runtime_event_at,
        run_request_seconds=http_redirect_received - run_request_started,
        request_to_container_running_seconds=container_running - run_request_started,
        request_to_container_id_seconds=container_id_visible - run_request_started,
        request_to_graph_visible_seconds=graph_visible - run_request_started,
        request_to_first_runtime_event_seconds=first_runtime_event
        - run_request_started,
    )
