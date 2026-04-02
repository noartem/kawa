import base64
import os
import time
import urllib.parse
import urllib.request
import uuid

import docker
import pytest
from docker.errors import APIError, NotFound

from tests.fixtures.clients import FlowManagerApi, UIClient
from tests.helpers.config import settings
from tests.helpers.docker_observer import DockerObserver


def _purge_flow_manager_queues() -> None:
    rabbitmq_api = os.getenv("E2E_RABBITMQ_API_URL", "http://rabbitmq:15672/api")
    rabbitmq_user = os.getenv("E2E_RABBITMQ_USER", "kawa")
    rabbitmq_password = os.getenv("E2E_RABBITMQ_PASSWORD", "secret")
    queue_names = (
        os.getenv("FLOW_MANAGER_COMMAND_QUEUE", "flow-manager.commands"),
        os.getenv("FLOW_MANAGER_RESPONSE_QUEUE", "flow-manager.responses"),
        os.getenv("FLOW_MANAGER_UI_EVENT_QUEUE", "flow-manager.ui.events"),
    )
    auth_header = base64.b64encode(
        f"{rabbitmq_user}:{rabbitmq_password}".encode("utf-8")
    ).decode("ascii")

    for queue_name in queue_names:
        encoded_name = urllib.parse.quote(queue_name, safe="")
        request = urllib.request.Request(
            f"{rabbitmq_api}/queues/%2F/{encoded_name}/contents",
            headers={"Authorization": f"Basic {auth_header}"},
            method="DELETE",
        )
        with urllib.request.urlopen(request, timeout=10):
            pass


def _cleanup_runtime_state(test_run_id: str) -> None:
    client = docker.from_env()

    try:
        containers = []
        for _ in range(3):
            try:
                containers = client.containers.list(all=True)
                break
            except (APIError, NotFound):
                time.sleep(1)

        for container in containers:
            try:
                labels = container.labels or {}
                container_name = getattr(container, "name", "") or ""
            except (APIError, NotFound):
                continue

            is_flow_container = (
                container_name.startswith("flow-")
                or bool(labels.get("kawaflow.flow_id"))
                or bool(labels.get("kawaflow.flow_run_id"))
            )
            if not is_flow_container:
                continue

            if test_run_id and labels.get("kawaflow.test_run_id") not in {
                None,
                test_run_id,
            }:
                continue

            try:
                container.remove(force=True)
            except (APIError, NotFound):
                continue
    finally:
        client.close()

    _purge_flow_manager_queues()


@pytest.fixture(scope="session")
def e2e_settings():
    return settings


@pytest.fixture(scope="session")
def ui_client(e2e_settings):
    client = UIClient(e2e_settings.base_url)
    email = f"e2e-{uuid.uuid4().hex}@example.com"
    password = "e2e-password"
    client.register("E2E User", email, password)
    client.login(email, password)
    yield client
    client.close()


@pytest.fixture(scope="session")
def flow_manager_api(e2e_settings):
    api = FlowManagerApi(e2e_settings.flow_manager_url)
    yield api
    api.close()


@pytest.fixture(scope="session")
def docker_observer():
    return DockerObserver()


@pytest.fixture(autouse=True)
def isolate_runtime_state(e2e_settings):
    _cleanup_runtime_state(e2e_settings.test_run_id)
    yield
    _cleanup_runtime_state(e2e_settings.test_run_id)
