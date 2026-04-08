from __future__ import annotations

import uuid
from pathlib import Path

import pytest

from tests.helpers.storage_chain import (
    STORAGE_CHAIN_FLOW_CODE,
    STORAGE_CHAIN_INITIAL_STORAGE,
    assert_storage_chain_budget,
    load_storage_chain_budget,
    measure_storage_chain,
    write_storage_chain_artifact,
)


@pytest.mark.performance
class TestStorageChainPerformance:
    def test_storage_chain_stays_within_budget(
        self,
        ui_client,
        e2e_settings,
    ):
        flow_name = f"E2E Storage Chain Perf {uuid.uuid4().hex[:8]}"
        flow = ui_client.create_flow(flow_name, STORAGE_CHAIN_FLOW_CODE)
        ui_client.update_storage(
            flow.flow_id,
            "development",
            STORAGE_CHAIN_INITIAL_STORAGE,
        )
        artifact_path = None

        try:
            measurement = measure_storage_chain(
                ui_client,
                e2e_settings,
                flow.flow_id,
                slug="chain-start",
                order_id=4242,
            )
            artifact_path = write_storage_chain_artifact(
                measurement,
                e2e_settings.perf_artifact_dir,
            )
            budget = load_storage_chain_budget(
                Path(__file__).resolve().parent
                / "fixtures"
                / "storage_chain_performance_budget.json"
            )

            try:
                assert_storage_chain_budget(measurement, budget)
            except AssertionError as exc:
                raise AssertionError(f"{exc}\nartifact: {artifact_path}") from exc
        finally:
            try:
                container_id = ui_client.wait_for_container_id(flow.flow_id, timeout=5)
                if container_id:
                    ui_client.stop_flow(flow.flow_id)
                    ui_client.wait_for_development_run_to_stop(
                        flow.flow_id,
                        timeout=90,
                    )
            except Exception:
                pass
            ui_client.delete_flow(flow.flow_id)
