import asyncio
import json
import os
import shutil
from pathlib import Path
from uuid import uuid4

import aio_pika
import docker
import pytest
from docker.errors import ImageNotFound


def _load_env_value(key: str) -> str | None:
    env_path = Path(__file__).resolve().parents[1] / ".env"
    if not env_path.exists():
        return None

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            continue
        entry_key, value = line.split("=", 1)
        entry_key = entry_key.strip()
        if entry_key == key:
            return value.strip().strip('"').strip("'")
    return None


def _configure_e2e_env(run_id: str) -> dict:
    command_queue = f"flow-manager.commands.e2e.{run_id}"
    response_queue = f"flow-manager.responses.e2e.{run_id}"
    event_exchange = f"flow-manager.events.e2e.{run_id}"

    rabbitmq_url = os.getenv("RABBITMQ_URL")
    if not rabbitmq_url:
        rabbitmq_url = _load_env_value("RABBITMQ_URL")
    if not rabbitmq_url:
        rabbitmq_url = "amqp://guest:guest@localhost:5672/"

    os.environ["MESSAGING_BACKEND"] = "rabbitmq"
    os.environ["RABBITMQ_URL"] = rabbitmq_url
    os.environ["FLOW_MANAGER_COMMAND_QUEUE"] = command_queue
    os.environ["FLOW_MANAGER_RESPONSE_QUEUE"] = response_queue
    os.environ["FLOW_MANAGER_EVENT_EXCHANGE"] = event_exchange

    return {
        "command_queue": command_queue,
        "response_queue": response_queue,
        "event_exchange": event_exchange,
        "rabbitmq_url": rabbitmq_url,
    }


def _require_docker() -> docker.DockerClient:
    client = docker.from_env()
    try:
        client.ping()
    except Exception as exc:
        pytest.fail(f"Docker is not available: {exc}")
    return client


def _ensure_image(client: docker.DockerClient, image: str) -> None:
    try:
        client.images.get(image)
    except ImageNotFound:
        client.images.pull(image)


async def _connect_rabbitmq(
    url: str,
) -> tuple[aio_pika.abc.AbstractRobustConnection, aio_pika.abc.AbstractChannel]:
    connection = await aio_pika.connect_robust(url)
    channel = await connection.channel()
    await channel.set_qos(prefetch_count=5)
    return connection, channel


async def _declare_resources(
    channel: aio_pika.abc.AbstractChannel,
    command_queue_name: str,
    response_queue_name: str,
    event_exchange_name: str,
) -> tuple[
    aio_pika.abc.AbstractExchange,
    aio_pika.abc.AbstractQueue,
    aio_pika.abc.AbstractQueue,
    aio_pika.abc.AbstractQueue,
]:
    event_exchange = await channel.declare_exchange(
        event_exchange_name,
        aio_pika.ExchangeType.TOPIC,
        durable=True,
    )
    command_queue = await channel.declare_queue(command_queue_name, durable=True)
    await command_queue.bind(event_exchange, routing_key="command.*")
    response_queue = await channel.declare_queue(response_queue_name, durable=True)
    event_queue = await channel.declare_queue("", exclusive=True, auto_delete=True)
    await event_queue.bind(event_exchange, routing_key="event.*")
    return event_exchange, command_queue, response_queue, event_queue


async def _publish_command(
    exchange: aio_pika.abc.AbstractExchange,
    action: str,
    data: dict,
    reply_to: str,
    correlation_id: str,
) -> None:
    payload = {
        "action": action,
        "data": data,
        "reply_to": reply_to,
        "correlation_id": correlation_id,
    }
    message = aio_pika.Message(
        body=json.dumps(payload).encode("utf-8"),
        correlation_id=correlation_id,
        content_type="application/json",
    )
    await exchange.publish(message, routing_key=f"command.{action}")


async def _await_response(
    queue: aio_pika.abc.AbstractQueue,
    correlation_id: str,
    timeout: float = 20,
) -> dict:
    loop = asyncio.get_event_loop()
    deadline = loop.time() + timeout

    while True:
        remaining = deadline - loop.time()
        if remaining <= 0:
            raise AssertionError("Timed out waiting for response message")
        message = await queue.get(timeout=remaining, fail=False)
        if message is None:
            continue
        async with message.process():
            payload = json.loads(message.body.decode("utf-8"))
        if message.correlation_id == correlation_id:
            return payload


async def _await_event(
    queue: aio_pika.abc.AbstractQueue,
    routing_key: str,
    timeout: float = 20,
) -> dict:
    loop = asyncio.get_event_loop()
    deadline = loop.time() + timeout

    while True:
        remaining = deadline - loop.time()
        if remaining <= 0:
            raise AssertionError(f"Timed out waiting for event {routing_key}")
        message = await queue.get(timeout=remaining, fail=False)
        if message is None:
            continue
        async with message.process():
            payload = json.loads(message.body.decode("utf-8"))
        if message.routing_key == routing_key:
            return payload


async def _await_events(
    queue: aio_pika.abc.AbstractQueue,
    routing_keys: set[str],
    timeout: float = 20,
) -> dict[str, dict]:
    loop = asyncio.get_event_loop()
    deadline = loop.time() + timeout
    remaining_keys = set(routing_keys)
    found: dict[str, dict] = {}

    while remaining_keys:
        remaining = deadline - loop.time()
        if remaining <= 0:
            missing = ", ".join(sorted(remaining_keys))
            raise AssertionError(f"Timed out waiting for events: {missing}")
        message = await queue.get(timeout=remaining, fail=False)
        if message is None:
            continue
        async with message.process():
            payload = json.loads(message.body.decode("utf-8"))
        if message.routing_key in remaining_keys:
            found[message.routing_key] = payload
            remaining_keys.remove(message.routing_key)

    return found


async def _wait_for_container_status(
    client: docker.DockerClient,
    container_id: str,
    expected: str,
    timeout: float = 20,
) -> None:
    loop = asyncio.get_event_loop()
    deadline = loop.time() + timeout

    while True:
        remaining = deadline - loop.time()
        if remaining <= 0:
            raise AssertionError(f"Timed out waiting for container status '{expected}'")
        container = client.containers.get(container_id)
        container.reload()
        if container.status == expected:
            return
        await asyncio.sleep(1)


async def _wait_for_container_removal(
    client: docker.DockerClient,
    container_id: str,
    timeout: float = 20,
) -> None:
    loop = asyncio.get_event_loop()
    deadline = loop.time() + timeout
    last_error = None

    while True:
        remaining = deadline - loop.time()
        if remaining <= 0:
            raise AssertionError(
                f"Timed out waiting for container removal: {last_error}"
            )
        try:
            client.containers.get(container_id)
        except Exception as exc:
            last_error = exc
            return
        await asyncio.sleep(1)


@pytest.mark.asyncio
@pytest.mark.e2e
async def test_e2e_create_stop_delete_container_via_rabbitmq():
    run_id = uuid4().hex
    socket_dir = f"/tmp/kawaflow-e2e-{run_id[:8]}"
    env = _configure_e2e_env(run_id)
    docker_client = _require_docker()
    _ensure_image(docker_client, "alpine:3.19")

    from main import FlowManagerApp

    app = FlowManagerApp(socket_dir=socket_dir)
    await app.startup()

    connection = None
    channel = None
    container_id = None
    try:
        connection, channel = await _connect_rabbitmq(env["rabbitmq_url"])
        event_exchange, _, response_queue, event_queue = await _declare_resources(
            channel,
            env["command_queue"],
            env["response_queue"],
            env["event_exchange"],
        )

        container_name = f"kawaflow-e2e-{run_id[:8]}"
        correlation_id = f"create-{run_id}"
        await _publish_command(
            event_exchange,
            "create_container",
            {
                "image": "alpine:3.19",
                "name": container_name,
                "command": ["sleep", "60"],
            },
            reply_to=env["response_queue"],
            correlation_id=correlation_id,
        )

        response = await _await_response(response_queue, correlation_id)
        assert response["ok"] is True
        container_id = response["data"]["container_id"]

        events = await _await_events(
            event_queue, {"event.container_created", "event.activity"}
        )
        created_event = events["event.container_created"]
        activity_event = events["event.activity"]
        assert created_event["container_id"] == container_id
        assert created_event["name"] == container_name
        assert activity_event["type"] == "container_created"
        assert activity_event["container_id"] == container_id

        await _wait_for_container_status(docker_client, container_id, "running")

        stop_id = f"stop-{run_id}"
        await _publish_command(
            event_exchange,
            "stop_container",
            {"container_id": container_id},
            reply_to=env["response_queue"],
            correlation_id=stop_id,
        )
        stop_response = await _await_response(response_queue, stop_id)
        assert stop_response["ok"] is True

        await _wait_for_container_status(docker_client, container_id, "exited")

        delete_id = f"delete-{run_id}"
        await _publish_command(
            event_exchange,
            "delete_container",
            {"container_id": container_id},
            reply_to=env["response_queue"],
            correlation_id=delete_id,
        )
        delete_response = await _await_response(response_queue, delete_id)
        assert delete_response["ok"] is True

        await _wait_for_container_removal(docker_client, container_id)
    finally:
        if container_id:
            try:
                docker_client.containers.get(container_id).remove(force=True)
            except Exception:
                pass
        if channel:
            await channel.close()
        if connection:
            await connection.close()
        await app.shutdown()
        shutil.rmtree(socket_dir, ignore_errors=True)


@pytest.mark.asyncio
@pytest.mark.e2e
async def test_e2e_unknown_container_returns_error():
    run_id = uuid4().hex
    socket_dir = f"/tmp/kawaflow-e2e-{run_id[:8]}"
    env = _configure_e2e_env(run_id)
    _require_docker()

    from main import FlowManagerApp

    app = FlowManagerApp(socket_dir=socket_dir)
    await app.startup()

    connection = None
    channel = None
    try:
        connection, channel = await _connect_rabbitmq(env["rabbitmq_url"])
        event_exchange, _, response_queue, _ = await _declare_resources(
            channel,
            env["command_queue"],
            env["response_queue"],
            env["event_exchange"],
        )

        correlation_id = f"delete-missing-{run_id}"
        await _publish_command(
            event_exchange,
            "delete_container",
            {"container_id": f"missing-{run_id}"},
            reply_to=env["response_queue"],
            correlation_id=correlation_id,
        )

        response = await _await_response(response_queue, correlation_id)
        assert response["error"] is True
        assert response["error_type"] == "container_not_found"
    finally:
        if channel:
            await channel.close()
        if connection:
            await connection.close()
        await app.shutdown()
        shutil.rmtree(socket_dir, ignore_errors=True)
