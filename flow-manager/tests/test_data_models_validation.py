"""
Test validation and functionality of core data models.
"""

import pytest
from datetime import datetime, timedelta
from models import (
    ContainerState,
    ContainerHealth,
    ContainerConfig,
    ContainerInfo,
    ContainerStatus,
    SocketMessage,
    ErrorResponse,
    CreateContainer,
    ContainerOperation,
    UpdateContainer,
    SendMessage,
    ActivityLog,
)


def test_container_state_enum():
    """Test ContainerState enum values."""
    assert ContainerState.CREATED.value == "created"
    assert ContainerState.RUNNING.value == "running"
    assert ContainerState.STOPPED.value == "stopped"
    assert ContainerState.PAUSED.value == "paused"
    assert ContainerState.RESTARTING.value == "restarting"
    assert ContainerState.REMOVING.value == "removing"
    assert ContainerState.EXITED.value == "exited"
    assert ContainerState.DEAD.value == "dead"


def test_container_health_enum():
    """Test ContainerHealth enum values."""
    assert ContainerHealth.HEALTHY.value == "healthy"
    assert ContainerHealth.UNHEALTHY.value == "unhealthy"
    assert ContainerHealth.STARTING.value == "starting"
    assert ContainerHealth.NONE.value == "none"


def test_container_config_validation():
    """Test ContainerConfig model validation."""
    # Valid config
    config = ContainerConfig(
        image="nginx:latest",
        name="test-container",
        environment={"ENV": "test"},
        volumes={"/host": "/container"},
        ports={"80": 8080},
        command=["nginx", "-g", "daemon off;"],
        working_dir="/app",
    )

    assert config.image == "nginx:latest"
    assert config.name == "test-container"
    assert config.environment == {"ENV": "test"}
    assert config.volumes == {"/host": "/container"}
    assert config.ports == {"80": 8080}
    assert config.command == ["nginx", "-g", "daemon off;"]
    assert config.working_dir == "/app"

    # Minimal config (only image required)
    minimal_config = ContainerConfig(image="alpine:latest")
    assert minimal_config.image == "alpine:latest"
    assert minimal_config.name is None
    assert minimal_config.environment == {}
    assert minimal_config.volumes == {}
    assert minimal_config.ports == {}
    assert minimal_config.command is None
    assert minimal_config.working_dir is None


def test_container_info_validation():
    """Test ContainerInfo model validation."""
    now = datetime.now()
    info = ContainerInfo(
        id="abc123",
        name="test-container",
        status="running",
        image="nginx:latest",
        created=now,
        socket_path="/var/run/abc123.sock",
        ports={"80": 8080},
        environment={"ENV": "test"},
    )

    assert info.id == "abc123"
    assert info.name == "test-container"
    assert info.status == "running"
    assert info.image == "nginx:latest"
    assert info.created == now
    assert info.socket_path == "/var/run/abc123.sock"
    assert info.ports == {"80": 8080}
    assert info.environment == {"ENV": "test"}


def test_container_status_validation():
    """Test ContainerStatus model validation."""
    now = datetime.now()
    uptime = timedelta(hours=2, minutes=30)

    status = ContainerStatus(
        id="abc123",
        state=ContainerState.RUNNING,
        health=ContainerHealth.HEALTHY,
        uptime=uptime,
        socket_connected=True,
        last_communication=now,
        resource_usage={"cpu": 25.5, "memory": "128MB"},
    )

    assert status.id == "abc123"
    assert status.state == ContainerState.RUNNING
    assert status.health == ContainerHealth.HEALTHY
    assert status.uptime == uptime
    assert status.socket_connected is True
    assert status.last_communication == now
    assert status.resource_usage == {"cpu": 25.5, "memory": "128MB"}


def test_socket_message_validation():
    """Test SocketMessage model validation."""
    message = SocketMessage(command="dump", data={"key": "value", "number": 42})

    assert message.command == "dump"
    assert message.data == {"key": "value", "number": 42}
    assert isinstance(message.timestamp, datetime)


def test_error_response_validation():
    """Test ErrorResponse model validation."""
    now = datetime.now()
    error = ErrorResponse(
        error_type="docker_api_error",
        message="Container not found",
        details={"container_id": "abc123", "operation": "start"},
        timestamp=now,
    )

    assert error.error is True
    assert error.error_type == "docker_api_error"
    assert error.message == "Container not found"
    assert error.details == {"container_id": "abc123", "operation": "start"}
    assert error.timestamp == now


def test_socket_io_event_models():
    """Test Socket.IO event data models."""
    # CreateContainer
    create_event = CreateContainer(
        image="nginx:latest",
        name="test-container",
        storage={"settings": {"profile": {"language": "en"}}},
        environment={"ENV": "test"},
        volumes={"/host": "/container"},
        ports={"80": 8080},
    )
    assert create_event.image == "nginx:latest"
    assert create_event.name == "test-container"
    assert create_event.storage == {"settings": {"profile": {"language": "en"}}}

    # ContainerOperation
    op_event = ContainerOperation(container_id="abc123")
    assert op_event.container_id == "abc123"

    # UpdateContainer
    update_event = UpdateContainer(container_id="abc123", code_path="/path/to/code")
    assert update_event.container_id == "abc123"
    assert update_event.code_path == "/path/to/code"

    # SendMessage
    send_event = SendMessage(
        container_id="abc123", message={"command": "dump", "data": {}}
    )
    assert send_event.container_id == "abc123"
    assert send_event.message == {"command": "dump", "data": {}}

    # ActivityLog
    activity_event = ActivityLog(
        activity_type="container_created",
        container_id="abc123",
        message="Container created successfully",
        details={"image": "nginx:latest"},
    )
    assert activity_event.activity_type == "container_created"
    assert activity_event.container_id == "abc123"
    assert activity_event.message == "Container created successfully"
    assert activity_event.details == {"image": "nginx:latest"}
    assert isinstance(activity_event.timestamp, datetime)


def test_pydantic_validation_errors():
    """Test that Pydantic validation works for invalid data."""
    # Missing required field
    with pytest.raises(ValueError):
        ContainerConfig()  # Missing required 'image' field

    # Invalid enum value
    with pytest.raises(ValueError):
        # Using ContainerState constructor to create an invalid state
        invalid_state = ContainerState("invalid_state")
        ContainerStatus(
            id="abc123",
            state=invalid_state,
            health=ContainerHealth.HEALTHY,
            socket_connected=True,
        )
