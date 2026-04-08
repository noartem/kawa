"""
Core data models for container lifecycle management.

This module defines Pydantic models for container configuration, status,
and communication data structures used throughout the flow-manager service.
"""

from datetime import datetime, timedelta
from enum import Enum
from typing import Any, Dict, List, Optional, Union

from pydantic import BaseModel, Field, field_validator


class ContainerState(Enum):
    """Container execution states."""

    CREATED = "created"
    RUNNING = "running"
    STOPPED = "stopped"
    PAUSED = "paused"
    RESTARTING = "restarting"
    REMOVING = "removing"
    EXITED = "exited"
    DEAD = "dead"


class ContainerHealth(Enum):
    """Container health status."""

    HEALTHY = "healthy"
    UNHEALTHY = "unhealthy"
    STARTING = "starting"
    NONE = "none"


class ContainerConfig(BaseModel):
    """Configuration for creating a new container."""

    image: str = Field(..., description="Docker image name")
    name: Optional[str] = Field(None, description="Container name")
    labels: Dict[str, str] = Field(
        default_factory=dict, description="Docker labels for container metadata"
    )
    environment: Dict[str, str] = Field(
        default_factory=dict, description="Environment variables"
    )
    volumes: Dict[str, str] = Field(
        default_factory=dict, description="Volume mounts (host_path: container_path)"
    )
    ports: Dict[str, int] = Field(
        default_factory=dict, description="Port mappings (container_port: host_port)"
    )
    command: Optional[List[str]] = Field(None, description="Command to run")
    working_dir: Optional[str] = Field(None, description="Working directory")

    @field_validator("image")
    @classmethod
    def validate_image(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Image name cannot be empty")
        return v.strip()

    @field_validator("name")
    @classmethod
    def validate_name(cls, v: Optional[str]) -> Optional[str]:
        if v is not None and not v.strip():
            raise ValueError("Container name cannot be empty string")
        return v.strip() if v else None


class ContainerInfo(BaseModel):
    """Information about a container."""

    id: str = Field(..., description="Container ID")
    name: str = Field(..., description="Container name")
    status: str = Field(..., description="Container status")
    image: str = Field(..., description="Docker image")
    created: Union[datetime, str] = Field(..., description="Creation timestamp")
    socket_path: str = Field(..., description="Unix socket path")
    ports: Dict[str, int] = Field(default_factory=dict, description="Port mappings")
    environment: Dict[str, str] = Field(
        default_factory=dict, description="Environment variables"
    )

    @field_validator("created", mode="before")
    @classmethod
    def validate_created(cls, v: Any) -> datetime:
        """Validate created is a datetime."""
        if isinstance(v, datetime):
            return v
        if isinstance(v, str):
            try:
                return datetime.fromisoformat(v)
            except ValueError:
                pass
        raise ValueError(f"Invalid datetime value: {v}")


class ContainerStatus(BaseModel):
    """Detailed status information for a container."""

    id: str = Field(..., description="Container ID")
    state: Union[ContainerState, str] = Field(..., description="Container state")
    health: Union[ContainerHealth, str] = Field(..., description="Container health")
    uptime: Optional[Union[timedelta, str]] = Field(
        None, description="Container uptime"
    )
    socket_connected: bool = Field(..., description="Socket connection status")
    last_communication: Optional[datetime] = Field(
        None, description="Last communication timestamp"
    )
    resource_usage: Dict[str, Any] = Field(
        default_factory=dict, description="Resource usage metrics"
    )

    @field_validator("state", mode="before")
    @classmethod
    def validate_state(cls, v: Any) -> ContainerState:
        """Convert string state to ContainerState enum if needed."""
        if isinstance(v, str):
            try:
                return ContainerState(v)
            except ValueError:
                raise ValueError(f"Invalid container state: {v}")
        return v

    @field_validator("health", mode="before")
    @classmethod
    def validate_health(cls, v: Any) -> ContainerHealth:
        """Convert string health to ContainerHealth enum if needed."""
        if isinstance(v, str):
            try:
                return ContainerHealth(v)
            except ValueError:
                raise ValueError(f"Invalid container health: {v}")
        return v

    @field_validator("uptime", mode="before")
    @classmethod
    def validate_uptime(cls, v: Any) -> Optional[timedelta]:
        """Validate uptime is a timedelta or None."""
        if v is None:
            return None
        if isinstance(v, timedelta):
            return v
        raise ValueError(f"Invalid timedelta value: {v}")


class SocketMessage(BaseModel):
    """Message structure for Unix socket communication."""

    command: str = Field(..., description="Command type")
    data: Dict[str, Any] = Field(default_factory=dict, description="Message payload")
    timestamp: datetime = Field(
        default_factory=datetime.now, description="Message timestamp"
    )

    @field_validator("command")
    @classmethod
    def validate_command(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Command cannot be empty")
        return v.strip()


class ErrorResponse(BaseModel):
    """Standardized error response format."""

    error: bool = Field(True, description="Error flag")
    error_type: str = Field(..., description="Error category")
    message: str = Field(..., description="Error message")
    details: Dict[str, Any] = Field(
        default_factory=dict, description="Additional error details"
    )
    timestamp: datetime = Field(
        default_factory=datetime.now, description="Error timestamp"
    )

    @field_validator("error_type")
    @classmethod
    def validate_error_type(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Error type cannot be empty")
        return v.strip()

    @field_validator("message")
    @classmethod
    def validate_message(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Error message cannot be empty")
        return v.strip()


# Socket.IO Event Data Models


class CreateContainer(BaseModel):
    """Data for create_container Socket.IO event."""

    image: str = Field(..., description="Docker image name")
    name: Optional[str] = Field(None, description="Container name")
    flow_id: Optional[int] = Field(None, description="Flow ID")
    flow_run_id: Optional[int] = Field(None, description="Flow run ID")
    flow_name: Optional[str] = Field(None, description="Flow name")
    graph_hash: Optional[str] = Field(None, description="Runtime graph hash")
    test_run_id: Optional[str] = Field(None, description="E2E test run ID")
    actors: List[Dict[str, Any]] = Field(default_factory=list)
    events: List[Dict[str, Any]] = Field(default_factory=list)
    labels: Dict[str, str] = Field(default_factory=dict)
    storage: Union[Dict[str, Any], List[Any]] = Field(default_factory=dict)
    environment: Dict[str, str] = Field(default_factory=dict)
    volumes: Dict[str, str] = Field(default_factory=dict)
    ports: Dict[str, int] = Field(default_factory=dict)
    command: Optional[List[str]] = Field(None, description="Command to run")
    working_dir: Optional[str] = Field(None, description="Working directory")

    @field_validator("image")
    @classmethod
    def validate_image(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Image name cannot be empty")
        return v.strip()

    @field_validator("name")
    @classmethod
    def validate_name(cls, v: Optional[str]) -> Optional[str]:
        if v is not None and not v.strip():
            raise ValueError("Container name cannot be empty string")
        return v.strip() if v else None

    @field_validator("flow_name", "graph_hash", "test_run_id")
    @classmethod
    def validate_optional_string(cls, v: Optional[str]) -> Optional[str]:
        if v is not None and not v.strip():
            raise ValueError("Optional string fields cannot be empty")
        return v.strip() if v else None


class GenerateLock(BaseModel):
    """Data for generate_lock command."""

    flow_id: int = Field(..., description="Flow ID")
    flow_run_id: int = Field(..., description="FlowRun ID")
    image: str = Field(..., description="Docker image name")
    code: str = Field(..., description="Python code for main.py")

    @field_validator("image")
    @classmethod
    def validate_image(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Image name cannot be empty")
        return v.strip()


class ContainerOperation(BaseModel):
    """Data for container operation Socket.IO events."""

    container_id: str = Field(..., description="Container ID")

    @field_validator("container_id")
    @classmethod
    def validate_container_id(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Container ID cannot be empty")
        return v.strip()


class UpdateContainer(BaseModel):
    """Data for update_container Socket.IO event."""

    container_id: str = Field(..., description="Container ID")
    code_path: str = Field(..., description="Path to new code")

    @field_validator("container_id")
    @classmethod
    def validate_container_id(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Container ID cannot be empty")
        return v.strip()

    @field_validator("code_path")
    @classmethod
    def validate_code_path(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Code path cannot be empty")
        return v.strip()


class SendMessage(BaseModel):
    """Data for send_message Socket.IO event."""

    container_id: str = Field(..., description="Container ID")
    message: Dict[str, Any] = Field(..., description="Message to send")

    @field_validator("container_id")
    @classmethod
    def validate_container_id(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Container ID cannot be empty")
        return v.strip()


class ActivityLog(BaseModel):
    """Data for activity log Socket.IO events."""

    activity_type: str = Field(..., description="Type of activity")
    container_id: str = Field(..., description="Container ID")
    message: str = Field(..., description="Activity message")
    details: Optional[Dict[str, Any]] = Field(
        None, description="Additional activity details"
    )
    timestamp: datetime = Field(
        default_factory=datetime.now, description="Activity timestamp"
    )

    @field_validator("activity_type")
    @classmethod
    def validate_activity_type(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Activity type cannot be empty")
        return v.strip()

    @field_validator("container_id")
    @classmethod
    def validate_container_id(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Container ID cannot be empty")
        return v.strip()

    @field_validator("message")
    @classmethod
    def validate_message(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Activity message cannot be empty")
        return v.strip()


# Enhanced Health Check Models


class HealthCheckType(Enum):
    """Types of health checks."""

    HTTP = "http"
    TCP = "tcp"
    COMMAND = "command"
    SOCKET = "socket"
    CUSTOM = "custom"


class HealthCheckConfig(BaseModel):
    """Configuration for container health checks."""

    enabled: bool = Field(True, description="Enable health checks")
    check_type: HealthCheckType = Field(
        HealthCheckType.HTTP, description="Type of health check"
    )
    endpoint: Optional[str] = Field(None, description="HTTP endpoint for health check")
    port: Optional[int] = Field(None, description="Port for TCP/HTTP health check")
    command: Optional[List[str]] = Field(
        None, description="Command for command-based health check"
    )
    interval: int = Field(30, description="Health check interval in seconds")
    timeout: int = Field(10, description="Health check timeout in seconds")
    retries: int = Field(3, description="Number of retries before marking unhealthy")
    start_period: int = Field(
        60, description="Grace period before starting health checks"
    )
    auto_recovery: bool = Field(True, description="Enable automatic recovery")
    recovery_action: str = Field(
        "restart", description="Recovery action: restart, recreate, none"
    )
    max_recovery_attempts: int = Field(3, description="Maximum recovery attempts")
    recovery_cooldown: int = Field(
        300, description="Cooldown period between recovery attempts"
    )

    @field_validator("interval")
    @classmethod
    def validate_interval(cls, v: int) -> int:
        if v < 5:
            raise ValueError("Health check interval must be at least 5 seconds")
        return v

    @field_validator("timeout")
    @classmethod
    def validate_timeout(cls, v: int) -> int:
        if v < 1:
            raise ValueError("Health check timeout must be at least 1 second")
        return v

    @field_validator("retries")
    @classmethod
    def validate_retries(cls, v: int) -> int:
        if v < 1:
            raise ValueError("Health check retries must be at least 1")
        return v

    @field_validator("recovery_action")
    @classmethod
    def validate_recovery_action(cls, v: str) -> str:
        valid_actions = {"restart", "recreate", "none"}
        if v not in valid_actions:
            raise ValueError(f"Recovery action must be one of: {valid_actions}")
        return v


class HealthCheckResult(BaseModel):
    """Result of a health check."""

    container_id: str = Field(..., description="Container ID")
    timestamp: datetime = Field(
        default_factory=datetime.now, description="Check timestamp"
    )
    health: ContainerHealth = Field(..., description="Health status")
    check_type: HealthCheckType = Field(..., description="Type of check performed")
    success: bool = Field(..., description="Whether check succeeded")
    response_time: Optional[float] = Field(None, description="Response time in seconds")
    error_message: Optional[str] = Field(None, description="Error message if failed")
    details: Dict[str, Any] = Field(
        default_factory=dict, description="Additional check details"
    )


class HealthStatusHistory(BaseModel):
    """Health status history for a container."""

    container_id: str = Field(..., description="Container ID")
    history: List[HealthCheckResult] = Field(
        default_factory=list, description="Health check history"
    )
    current_health: ContainerHealth = Field(
        ContainerHealth.NONE, description="Current health status"
    )
    consecutive_failures: int = Field(0, description="Consecutive failure count")
    last_healthy: Optional[datetime] = Field(None, description="Last healthy timestamp")
    last_unhealthy: Optional[datetime] = Field(
        None, description="Last unhealthy timestamp"
    )
    recovery_attempts: int = Field(0, description="Number of recovery attempts")
    last_recovery_attempt: Optional[datetime] = Field(
        None, description="Last recovery attempt timestamp"
    )

    def add_result(self, result: HealthCheckResult, max_history: int = 100) -> None:
        """Add a health check result to history."""
        self.history.append(result)

        # Keep only the most recent results
        if len(self.history) > max_history:
            self.history = self.history[-max_history:]

        # Update current status
        self.current_health = result.health

        # Update failure count
        if result.success:
            self.consecutive_failures = 0
            self.last_healthy = result.timestamp
        else:
            self.consecutive_failures += 1
            self.last_unhealthy = result.timestamp

    def should_recover(self, config: HealthCheckConfig) -> bool:
        """Check if container should be recovered."""
        if not config.auto_recovery or config.recovery_action == "none":
            return False

        if self.recovery_attempts >= config.max_recovery_attempts:
            return False

        # Check cooldown period
        if self.last_recovery_attempt:
            cooldown_elapsed = (
                datetime.now() - self.last_recovery_attempt
            ).total_seconds()
            if cooldown_elapsed < config.recovery_cooldown:
                return False

        return self.current_health == ContainerHealth.UNHEALTHY

    def record_recovery_attempt(self) -> None:
        """Record a recovery attempt."""
        self.recovery_attempts += 1
        self.last_recovery_attempt = datetime.now()


class HealthCheckConfigPayload(BaseModel):
    """Data for health check configuration Socket.IO events."""

    container_id: str = Field(..., description="Container ID")
    config: HealthCheckConfig = Field(..., description="Health check configuration")

    @field_validator("container_id")
    @classmethod
    def validate_container_id(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Container ID cannot be empty")
        return v.strip()
