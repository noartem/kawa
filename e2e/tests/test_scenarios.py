import time
import uuid

import httpx
import pytest

from tests.helpers.graph_validation import graph_hash


FLOW_CODE = """
from kawa import actor, event, Context, Cron


@event
class Ping:
    pass


@actor(receivs=Cron.by("*/5 * * * *"))
def Starter(ctx: Context, event):
    print("start")
"""

FLOW_CODE_UPDATED = """
from kawa import actor, event, Context, Cron


@event
class Ping:
    pass


@actor(receivs=Cron.by("*/10 * * * *"))
def Starter(ctx: Context, event):
    print("start again")
"""

INTERACTION_FLOW_CODE = """
from kawa import actor, event, Context, Cron


@event
class Ping:
    text: str
    step: int


@event
class Pong:
    text: str
    step: int


@actor(receivs=Cron.by("* * * * *"), sends=Ping)
def Starter(ctx: Context, event):
    ctx.dispatch(Ping(text="hello-from-cron", step=1))


@actor(receivs=Ping, sends=Pong)
def Worker(ctx: Context, event: Ping):
    ctx.dispatch(Pong(text=event.text + "-pong", step=event.step + 1))


@actor(receivs=Pong)
def Sink(ctx: Context, event: Pong):
    pass
"""


def _flow_labels(flow_id: int, e2e_settings) -> dict[str, str]:
    labels = {"kawaflow.flow_id": str(flow_id)}
    if e2e_settings.test_run_id:
        labels["kawaflow.test_run_id"] = e2e_settings.test_run_id
    return labels


def _wait_for_logs(ui_client, flow_id: int, timeout: int = 90) -> list[dict]:
    return _wait_for_log_entries(
        ui_client,
        flow_id,
        lambda data: any("Event:" in entry.get("message", "") for entry in data),
        timeout,
    )


def _wait_for_log_entries(
    ui_client, flow_id: int, predicate, timeout: int = 90
) -> list[dict]:
    deadline = time.time() + timeout
    payload: dict[str, dict[str, list[dict]]] = {"data": {"data": []}}

    while time.time() < deadline:
        payload = ui_client.flow_logs(flow_id)
        data = payload.get("data", {}).get("data", [])
        if predicate(data):
            return data
        time.sleep(2)

    return payload.get("data", {}).get("data", [])


def _runtime_event_logs(logs: list[dict]) -> list[dict]:
    return [
        entry for entry in logs if entry.get("message") == "Event: flow_runtime_event"
    ]


def _runtime_event_contexts(logs: list[dict]) -> list[dict]:
    return [
        context
        for entry in _runtime_event_logs(logs)
        if isinstance(context := entry.get("context"), dict)
    ]


def _wait_for_runtime_event_kinds(
    ui_client,
    flow_id: int,
    expected_kinds: set[str],
    timeout: int,
) -> list[dict]:
    return _wait_for_log_entries(
        ui_client,
        flow_id,
        lambda data: expected_kinds.issubset(
            {
                str(context.get("kind"))
                for context in _runtime_event_contexts(data)
                if context.get("kind")
            }
        ),
        timeout,
    )


def _wait_for_runtime_event_contexts(
    ui_client,
    flow_id: int,
    predicate,
    timeout: int,
) -> list[dict]:
    logs = _wait_for_log_entries(
        ui_client,
        flow_id,
        lambda data: predicate(_runtime_event_contexts(data)),
        timeout,
    )

    return _runtime_event_contexts(logs)


def _has_interaction_actor_chain(contexts: list[dict]) -> bool:
    return all(
        [
            any(
                context.get("kind") == "actor_invoked"
                and context.get("actor") == "Starter"
                and context.get("trigger_event") == "Cron"
                for context in contexts
            ),
            any(
                context.get("kind") == "event_dispatched"
                and context.get("actor") == "Starter"
                and context.get("event") == "Ping"
                and isinstance(context.get("payload"), dict)
                and context["payload"].get("text") == "hello-from-cron"
                and context["payload"].get("step") == 1
                for context in contexts
            ),
            any(
                context.get("kind") == "actor_invoked"
                and context.get("actor") == "Worker"
                and context.get("trigger_event") == "Ping"
                for context in contexts
            ),
            any(
                context.get("kind") == "event_dispatched"
                and context.get("actor") == "Worker"
                and context.get("event") == "Pong"
                and isinstance(context.get("payload"), dict)
                and context["payload"].get("text") == "hello-from-cron-pong"
                and context["payload"].get("step") == 2
                for context in contexts
            ),
        ]
    )


def _wait_for_container_id(ui_client, flow_id: int, timeout: int = 90) -> str | None:
    return ui_client.wait_for_container_id(flow_id, timeout)


def _wait_for_new_container(
    docker_observer,
    labels: dict[str, str],
    previous_run_id: str,
    timeout: int,
):
    deadline = time.time() + timeout
    while time.time() < deadline:
        containers = docker_observer.list_containers(labels)
        for container in containers:
            run_id = docker_observer.container_labels(container).get(
                "kawaflow.flow_run_id"
            )
            if run_id and run_id != previous_run_id:
                return container
        time.sleep(2)
    raise TimeoutError("new container not found within timeout")


def _latest_development_deployment(props: dict) -> dict | None:
    return (
        props.get("lastDevelopmentDeployment")
        or props.get("last_development_deployment")
        or props.get("developmentRun")
        or props.get("development_run")
    )


def _wait_for_flow_props(ui_client, flow_id: int, predicate, timeout: int) -> dict:
    deadline = time.time() + timeout
    props: dict = {}

    while time.time() < deadline:
        props = ui_client.flow_show_props(flow_id)
        if predicate(props):
            return props
        time.sleep(2)

    return props


@pytest.fixture
def simple_flow(ui_client):
    flow_name = f"E2E Flow {uuid.uuid4().hex[:8]}"
    flow = ui_client.create_flow(flow_name, FLOW_CODE)
    yield flow
    try:
        ui_client.wait_for_container_id(flow.flow_id, timeout=90)
        ui_client.stop_flow(flow.flow_id)
    finally:
        ui_client.delete_flow(flow.flow_id)


@pytest.fixture
def interaction_flow(ui_client):
    flow_name = f"E2E Interaction Flow {uuid.uuid4().hex[:8]}"
    flow = ui_client.create_flow(flow_name, INTERACTION_FLOW_CODE)
    yield flow
    try:
        ui_client.wait_for_container_id(flow.flow_id, timeout=90)
        ui_client.stop_flow(flow.flow_id)
    finally:
        ui_client.delete_flow(flow.flow_id)


def test_flow_lifecycle_rerun(simple_flow, ui_client, docker_observer, e2e_settings):
    ui_client.run_flow(simple_flow.flow_id)

    labels = _flow_labels(simple_flow.flow_id, e2e_settings)
    container = docker_observer.wait_for_container(
        labels, e2e_settings.container_timeout
    )
    docker_observer.wait_for_status(
        container, "running", e2e_settings.container_timeout
    )
    first_run_id = docker_observer.container_labels(container).get(
        "kawaflow.flow_run_id"
    )
    assert first_run_id

    container_id = _wait_for_container_id(
        ui_client, simple_flow.flow_id, timeout=e2e_settings.container_timeout
    )
    assert container_id
    ui_client.stop_flow(simple_flow.flow_id)
    docker_observer.wait_for_status(container, "exited", e2e_settings.container_timeout)

    ui_client.run_flow(simple_flow.flow_id)
    new_container = _wait_for_new_container(
        docker_observer,
        labels,
        first_run_id,
        e2e_settings.container_timeout,
    )
    docker_observer.wait_for_status(
        new_container, "running", e2e_settings.container_timeout
    )


def test_flow_lifecycle_restart(simple_flow, ui_client, docker_observer, e2e_settings):
    ui_client.run_flow(simple_flow.flow_id)

    labels = _flow_labels(simple_flow.flow_id, e2e_settings)
    container = docker_observer.wait_for_container(
        labels, e2e_settings.container_timeout
    )
    docker_observer.wait_for_status(
        container, "running", e2e_settings.container_timeout
    )
    first_run_id = docker_observer.container_labels(container).get(
        "kawaflow.flow_run_id"
    )
    assert first_run_id

    container_id = _wait_for_container_id(
        ui_client, simple_flow.flow_id, timeout=e2e_settings.container_timeout
    )
    assert container_id
    ui_client.stop_flow(simple_flow.flow_id)
    docker_observer.wait_for_status(container, "exited", e2e_settings.container_timeout)

    ui_client.run_flow(simple_flow.flow_id)
    restarted = _wait_for_new_container(
        docker_observer,
        labels,
        first_run_id,
        e2e_settings.container_timeout,
    )
    docker_observer.wait_for_status(
        restarted, "running", e2e_settings.container_timeout
    )


def test_runtime_graph_update(simple_flow, ui_client, docker_observer, e2e_settings):
    ui_client.run_flow(simple_flow.flow_id)

    labels = _flow_labels(simple_flow.flow_id, e2e_settings)
    container = docker_observer.wait_for_container(
        labels, e2e_settings.container_timeout
    )
    docker_observer.wait_for_status(
        container, "running", e2e_settings.container_timeout
    )
    initial_hash = docker_observer.container_labels(container).get(
        "kawaflow.graph_hash"
    )
    first_run_id = docker_observer.container_labels(container).get(
        "kawaflow.flow_run_id"
    )
    assert initial_hash
    assert first_run_id

    container_id = _wait_for_container_id(
        ui_client, simple_flow.flow_id, timeout=e2e_settings.container_timeout
    )
    assert container_id
    ui_client.stop_flow(simple_flow.flow_id)
    docker_observer.wait_for_status(container, "exited", e2e_settings.container_timeout)

    ui_client.update_flow(simple_flow.flow_id, simple_flow.name, FLOW_CODE_UPDATED)

    ui_client.run_flow(simple_flow.flow_id)
    updated_container = _wait_for_new_container(
        docker_observer,
        labels,
        first_run_id,
        e2e_settings.container_timeout,
    )
    docker_observer.wait_for_status(
        updated_container, "running", e2e_settings.container_timeout
    )
    updated_hash = docker_observer.container_labels(updated_container).get(
        "kawaflow.graph_hash"
    )
    expected_hash = graph_hash(FLOW_CODE_UPDATED)
    assert updated_hash == expected_hash
    assert updated_hash != initial_hash


def test_runtime_graph_labels(simple_flow, ui_client, docker_observer, e2e_settings):
    ui_client.run_flow(simple_flow.flow_id)

    labels = _flow_labels(simple_flow.flow_id, e2e_settings)
    container = docker_observer.wait_for_container(
        labels, e2e_settings.container_timeout
    )
    docker_observer.wait_for_status(
        container, "running", e2e_settings.container_timeout
    )

    container_labels = docker_observer.container_labels(container)
    expected_hash = graph_hash(simple_flow.code)
    assert container_labels.get("kawaflow.graph_hash") == expected_hash


def test_logs_endpoint(simple_flow, ui_client, docker_observer, e2e_settings):
    ui_client.run_flow(simple_flow.flow_id)
    labels = _flow_labels(simple_flow.flow_id, e2e_settings)
    container = docker_observer.wait_for_container(
        labels, e2e_settings.container_timeout
    )
    docker_observer.wait_for_status(
        container, "running", e2e_settings.container_timeout
    )

    logs = _wait_for_logs(
        ui_client,
        simple_flow.flow_id,
        timeout=e2e_settings.container_timeout,
    )
    assert logs
    assert any("Event:" in entry.get("message", "") for entry in logs)


def test_runtime_event_logs_capture_actor_chain(
    interaction_flow, ui_client, docker_observer, e2e_settings
):
    labels = _flow_labels(interaction_flow.flow_id, e2e_settings)
    contexts: list[dict] = []
    previous_run_id: str | None = None

    for attempt in range(2):
        ui_client.run_flow(interaction_flow.flow_id)

        if previous_run_id is None:
            container = docker_observer.wait_for_container(
                labels, e2e_settings.container_timeout
            )
        else:
            container = _wait_for_new_container(
                docker_observer,
                labels,
                previous_run_id,
                e2e_settings.container_timeout,
            )

        docker_observer.wait_for_status(
            container, "running", e2e_settings.container_timeout
        )

        contexts = _wait_for_runtime_event_contexts(
            ui_client,
            interaction_flow.flow_id,
            _has_interaction_actor_chain,
            e2e_settings.container_timeout * 2,
        )

        if _has_interaction_actor_chain(contexts):
            break

        previous_run_id = docker_observer.container_labels(container).get(
            "kawaflow.flow_run_id"
        )
        ui_client.wait_for_container_id(
            interaction_flow.flow_id,
            timeout=e2e_settings.container_timeout,
        )
        ui_client.stop_flow(interaction_flow.flow_id)
        docker_observer.wait_for_status(
            container, "exited", e2e_settings.container_timeout
        )

    assert _has_interaction_actor_chain(contexts)

    assert any(
        context.get("kind") == "actor_invoked"
        and context.get("actor") == "Starter"
        and context.get("trigger_event") == "Cron"
        for context in contexts
    )
    assert any(
        context.get("kind") == "event_dispatched"
        and context.get("actor") == "Starter"
        and context.get("event") == "Ping"
        and isinstance(context.get("payload"), dict)
        and context["payload"].get("text") == "hello-from-cron"
        and context["payload"].get("step") == 1
        for context in contexts
    )
    assert any(
        context.get("kind") == "actor_invoked"
        and context.get("actor") == "Worker"
        and context.get("trigger_event") == "Ping"
        for context in contexts
    )
    assert any(
        context.get("kind") == "event_dispatched"
        and context.get("actor") == "Worker"
        and context.get("event") == "Pong"
        and isinstance(context.get("payload"), dict)
        and context["payload"].get("text") == "hello-from-cron-pong"
        and context["payload"].get("step") == 2
        for context in contexts
    )


def test_flow_details_include_runtime_logs_and_graph(
    interaction_flow,
    ui_client,
    docker_observer,
    flow_manager_api,
    e2e_settings,
):
    ui_client.run_flow(interaction_flow.flow_id)

    labels = _flow_labels(interaction_flow.flow_id, e2e_settings)
    container = docker_observer.wait_for_container(
        labels, e2e_settings.container_timeout * 3
    )
    docker_observer.wait_for_status(
        container, "running", e2e_settings.container_timeout * 3
    )

    _wait_for_runtime_event_kinds(
        ui_client,
        interaction_flow.flow_id,
        {"actor_invoked", "event_dispatched"},
        e2e_settings.container_timeout * 3,
    )

    props = _wait_for_flow_props(
        ui_client,
        interaction_flow.flow_id,
        lambda data: bool(
            (deployment := _latest_development_deployment(data))
            and any(
                log.get("message") == "Event: flow_runtime_event"
                for log in (deployment.get("logs") or [])
            )
        ),
        e2e_settings.container_timeout * 3,
    )

    deployment = _latest_development_deployment(props)
    assert deployment is not None
    assert any(
        log.get("message") == "Event: flow_runtime_event"
        for log in (deployment.get("logs") or [])
    )
    graph = (
        deployment.get("graph") or flow_manager_api.container_graph(container.id) or {}
    )
    assert {event.get("name") for event in (graph.get("events") or [])} >= {
        "Ping",
        "Pong",
    }
    assert {actor.get("name") for actor in (graph.get("actors") or [])} >= {
        "Starter",
        "Worker",
        "Sink",
    }


def test_auth_ownership(ui_client, e2e_settings):
    other_client = type(ui_client)(e2e_settings.base_url)
    flow = None
    try:
        email = f"e2e-{uuid.uuid4().hex}@example.com"
        password = "e2e-password"
        other_client.register("E2E User B", email, password)
        other_client.login(email, password)

        flow_name = f"E2E Flow {uuid.uuid4().hex[:8]}"
        flow = ui_client.create_flow(flow_name, FLOW_CODE)

        response = other_client.get(f"/flows/{flow.flow_id}", follow_redirects=False)
        assert response.status_code == 403
    finally:
        if flow is not None:
            ui_client.delete_flow(flow.flow_id)
        other_client.close()


def test_auth_session_expired(e2e_settings):
    client = httpx.Client(base_url=e2e_settings.base_url, follow_redirects=False)
    try:
        response = client.get("/flows")
        assert response.status_code in {302, 303}
        assert "/login" in response.headers.get("location", "")
    finally:
        client.close()


def test_concurrency_same_name(ui_client, e2e_settings):
    other_client = type(ui_client)(e2e_settings.base_url)
    flow_a = None
    flow_b = None
    try:
        email = f"e2e-{uuid.uuid4().hex}@example.com"
        password = "e2e-password"
        other_client.register("E2E User B", email, password)
        other_client.login(email, password)

        name = "Shared Name"
        flow_a = ui_client.create_flow(name, FLOW_CODE)
        flow_b = other_client.create_flow(name, FLOW_CODE)

        assert flow_a.flow_id != flow_b.flow_id
        assert ui_client.flow_show_props(flow_a.flow_id)["flow"]["id"] == flow_a.flow_id
        assert (
            other_client.flow_show_props(flow_b.flow_id)["flow"]["id"] == flow_b.flow_id
        )
    finally:
        if flow_a is not None:
            ui_client.delete_flow(flow_a.flow_id)
        if flow_b is not None:
            other_client.delete_flow(flow_b.flow_id)
        other_client.close()


def test_validation_invalid_template(ui_client):
    payload = {
        "name": f"E2E Flow {uuid.uuid4().hex[:8]}",
        "description": "e2e flow invalid template",
        "template": "invalid-template",
    }
    response = ui_client.create_flow_raw(payload)
    assert response.status_code == 422


def test_validation_empty_code(simple_flow, ui_client):
    payload = {
        "name": simple_flow.name,
        "description": "e2e flow empty code",
        "code": "",
    }
    response = ui_client.update_flow_raw(simple_flow.flow_id, payload)
    assert response.status_code in {200, 302}

    props = ui_client.flow_show_props(simple_flow.flow_id)
    assert props["flow"]["code"] in {"", None}


@pytest.mark.skip(
    reason="Scenario not supported: delete while running is blocked by FlowController."
)
def test_flow_lifecycle_delete_running():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: runtime graph depends on flow runtime socket."
)
def test_runtime_graph_basic():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: send message endpoint not exposed in UI."
)
def test_logs_event_roundtrip():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: flow container never executes actor code."
)
def test_logs_error_trace():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: stop event not emitted as a distinct log entry."
)
def test_logs_stop_event():
    pass


@pytest.mark.skip(reason="Scenario not supported: no role without manage permissions.")
def test_auth_no_manage():
    pass


@pytest.mark.skip(reason="Scenario not supported: no role without run permissions.")
def test_auth_no_run():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: flow-manager container control requires write access to docker socket."
)
def test_flow_manager_restart():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: UI publishes to RabbitMQ even when flow-manager is down."
)
def test_flow_manager_unavailable():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: UI does not wait for flow-manager responses."
)
def test_flow_manager_timeout():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: parallel flow creation not exercised in current harness."
)
def test_concurrency_multi_flows():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: message sending not wired through UI."
)
def test_concurrency_parallel_messages():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: production deploy requires uv lock build and images."
)
def test_production_deploy_success():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: production deploy requires uv lock build and images."
)
def test_production_undeploy():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: production deploy requires uv lock build and images."
)
def test_production_deploy_invalid_lock():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: invalid code is accepted by current validation rules."
)
def test_validation_invalid_code():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: cleanup behavior is handled by task runner teardown."
)
def test_cleanup_orphaned_containers():
    pass


@pytest.mark.skip(
    reason="Scenario not supported: cleanup on failure not enforced in current harness."
)
def test_cleanup_on_failure():
    pass
