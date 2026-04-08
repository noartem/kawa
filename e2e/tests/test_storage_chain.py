from __future__ import annotations

import uuid

import pytest

from tests.helpers.storage_chain import (
    STORAGE_CHAIN_FLOW_CODE,
    STORAGE_CHAIN_INITIAL_STORAGE,
    development_storage,
    has_storage_chain_sequence,
    runtime_event_contexts,
    wait_for_accepted_webhook_dispatch,
    wait_for_development_webhook_url,
    wait_for_log_entries,
    wait_for_persisted_development_storage,
)


@pytest.fixture
def storage_chain_flow(ui_client):
    flow_name = f"Storage Chain E2E {uuid.uuid4().hex[:8]}"
    flow = ui_client.create_flow(flow_name, STORAGE_CHAIN_FLOW_CODE)
    ui_client.update_storage(
        flow.flow_id,
        "development",
        STORAGE_CHAIN_INITIAL_STORAGE,
    )

    yield flow

    try:
        container_id = ui_client.wait_for_container_id(flow.flow_id, timeout=5)
        if container_id:
            ui_client.stop_flow(flow.flow_id)
            ui_client.wait_for_development_run_to_stop(flow.flow_id, timeout=90)
    finally:
        ui_client.delete_flow(flow.flow_id)


def test_storage_is_shared_across_multi_actor_event_chain(
    storage_chain_flow,
    ui_client,
    e2e_settings,
):
    flow_id = storage_chain_flow.flow_id
    order_id = 4242

    ui_client.run_flow(flow_id)

    container_id = ui_client.wait_for_container_id(
        flow_id,
        timeout=e2e_settings.container_timeout,
    )
    assert container_id is not None

    webhook_url = wait_for_development_webhook_url(
        ui_client,
        flow_id,
        slug="chain-start",
        timeout=e2e_settings.graph_timeout,
    )
    response, _, _ = wait_for_accepted_webhook_dispatch(
        ui_client,
        webhook_url,
        {"order_id": order_id},
        timeout=e2e_settings.container_timeout,
    )

    assert response.status_code == 200
    assert response.json() == {"ok": True, "status": "accepted"}

    logs = wait_for_log_entries(
        ui_client,
        flow_id,
        lambda entries: has_storage_chain_sequence(runtime_event_contexts(entries)),
        timeout=max(e2e_settings.container_timeout, 45),
    )
    contexts = runtime_event_contexts(logs)

    assert has_storage_chain_sequence(contexts)

    storage = wait_for_persisted_development_storage(
        ui_client,
        flow_id,
        order_id=order_id,
        timeout=max(e2e_settings.container_timeout, 45),
    )

    assert storage["seed"] == STORAGE_CHAIN_INITIAL_STORAGE["seed"]
    assert storage["chain"]["steps"] == [
        {
            "actor": "FirstActor",
            "from_seed": "hello-storage",
            "counter_seen": 7,
        },
        {
            "actor": "SecondActor",
            "seed_flag": "seeded",
            "seen_first_value": "hello-storage|7",
        },
        {
            "actor": "ThirdActor",
            "seen_first_actor": "FirstActor",
            "seen_second_note": "hello-storage|7|4242",
        },
    ]
    assert storage["chain"]["shared"] == {
        "first_value": "hello-storage|7",
        "webhook": {
            "order_id": 4242,
        },
    }
    assert storage["chain"]["nested"] == {
        "deep": {
            "second": {
                "note": "hello-storage|7|4242",
            },
        },
    }
    assert storage["chain"]["final"] == {
        "status": "completed",
        "order_id": 4242,
        "shared_first": "hello-storage|7",
        "second_note": "hello-storage|7|4242",
        "deleted_state": "missing",
    }

    latest_storage = development_storage(ui_client.flow_show_props(flow_id))
    assert latest_storage["chain"]["steps"][1]["seen_first_value"] == "hello-storage|7"
    assert latest_storage["chain"]["steps"][2]["seen_first_actor"] == "FirstActor"
    assert latest_storage["chain"]["final"]["deleted_state"] == "missing"
