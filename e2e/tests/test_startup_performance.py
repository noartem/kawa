from __future__ import annotations

import uuid
from pathlib import Path

import pytest

from tests.helpers.performance import (
    assert_startup_budget,
    load_startup_budget,
    measure_cron_startup,
    write_startup_artifact,
)


PERFORMANCE_FLOW_CODE = """
from kawa import actor, event, Context, Cron


@event
class Ping:
    text: str


@actor(receivs=Cron.by("* * * * *"), sends=Ping)
def Starter(ctx: Context, event):
    ctx.dispatch(Ping(text="hello-from-cron"))
"""


@pytest.mark.performance
class TestStartupPerformance:
    def test_cron_startup_stays_within_budget(
        self,
        ui_client,
        docker_observer,
        e2e_settings,
    ):
        flow_name = f"E2E Startup Perf {uuid.uuid4().hex[:8]}"
        flow = ui_client.create_flow(flow_name, PERFORMANCE_FLOW_CODE)
        artifact_path = None

        try:
            measurement = measure_cron_startup(
                ui_client,
                docker_observer,
                e2e_settings,
                flow.flow_id,
            )
            artifact_path = write_startup_artifact(
                measurement,
                e2e_settings.perf_artifact_dir,
            )
            budget = load_startup_budget(
                Path(__file__).resolve().parent
                / "fixtures"
                / "startup_performance_budget.json"
            )

            try:
                assert_startup_budget(measurement, budget)
            except AssertionError as exc:
                raise AssertionError(f"{exc}\nartifact: {artifact_path}") from exc
        finally:
            try:
                container_id = ui_client.wait_for_container_id(flow.flow_id, timeout=5)
                if container_id:
                    ui_client.stop_flow(flow.flow_id)
            except Exception:
                pass
            ui_client.delete_flow(flow.flow_id)
