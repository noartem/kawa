import os
from pydantic import BaseModel, Field


class Settings(BaseModel):
    base_url: str = Field(
        default_factory=lambda: os.getenv("E2E_BASE_URL", "http://ui-app:8000")
    )
    flow_manager_url: str = Field(
        default_factory=lambda: os.getenv(
            "E2E_FLOW_MANAGER_URL", "http://flow-manager:8000"
        )
    )
    test_run_id: str = Field(default_factory=lambda: os.getenv("E2E_TEST_RUN_ID", ""))
    container_timeout: int = Field(
        default_factory=lambda: int(os.getenv("E2E_CONTAINER_TIMEOUT", "90"))
    )
    graph_timeout: int = Field(
        default_factory=lambda: int(os.getenv("E2E_GRAPH_TIMEOUT", "15"))
    )
    perf_artifact_dir: str = Field(
        default_factory=lambda: os.getenv(
            "E2E_PERF_ARTIFACT_DIR", "/tmp/kawaflow-e2e-performance"
        )
    )


settings = Settings()
