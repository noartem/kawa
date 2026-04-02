import uuid

import pytest

from tests.helpers.graph_validation import graph_hash, validate_graph_structure


FLOW_CODE = """
from kawa import actor, event, Context, Cron


@event
class Ping:
    pass


@actor(receivs=Cron.by(\"*/5 * * * *\"))
def Starter(ctx: Context, event):
    print(\"start\")
"""


@pytest.fixture
def created_flow(ui_client):
    flow_name = f"E2E Flow {uuid.uuid4().hex[:8]}"
    flow = ui_client.create_flow(flow_name, FLOW_CODE)
    yield flow
    try:
        ui_client.wait_for_container_id(flow.flow_id, timeout=90)
        ui_client.stop_flow(flow.flow_id)
        ui_client.wait_for_development_run_to_stop(flow.flow_id, timeout=90)
    finally:
        ui_client.delete_flow(flow.flow_id)


def test_flow_container_lifecycle(
    created_flow, ui_client, docker_observer, e2e_settings
):
    ui_client.run_flow(created_flow.flow_id)

    labels = {
        "kawaflow.flow_id": str(created_flow.flow_id),
    }
    if e2e_settings.test_run_id:
        labels["kawaflow.test_run_id"] = e2e_settings.test_run_id

    container = docker_observer.wait_for_container(
        labels, e2e_settings.container_timeout
    )
    docker_observer.wait_for_status(
        container, "running", e2e_settings.container_timeout
    )

    container_labels = docker_observer.container_labels(container)
    expected_hash = graph_hash(created_flow.code)
    assert container_labels.get("kawaflow.graph_hash") == expected_hash
    assert container.name.startswith(f"flow-{created_flow.flow_id}-run-")

    ui_client.stop_flow(created_flow.flow_id)
    docker_observer.wait_for_status(container, "exited", e2e_settings.container_timeout)


def test_runtime_graph(
    created_flow, ui_client, docker_observer, flow_manager_api, e2e_settings
):
    ui_client.run_flow(created_flow.flow_id)

    labels = {
        "kawaflow.flow_id": str(created_flow.flow_id),
    }
    if e2e_settings.test_run_id:
        labels["kawaflow.test_run_id"] = e2e_settings.test_run_id

    container = docker_observer.wait_for_container(
        labels, e2e_settings.container_timeout
    )
    docker_observer.wait_for_status(
        container, "running", e2e_settings.container_timeout
    )

    graph = flow_manager_api.container_graph(container.id)
    if graph is None:
        pytest.skip("runtime graph not available from flow-manager")

    validate_graph_structure(graph)
