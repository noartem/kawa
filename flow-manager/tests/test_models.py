"""
Tests for data models.

This module tests the core data models including:
- Container state and health enums
- Container configuration
- Container information and status
- Socket messages and events
- Error responses
"""

import pytest
from datetime import datetime, timedelta
from pydantic import ValidationError
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


def test_container_enums():
    """Test container state and health enums."""
    # Test ContainerState enum
    assert ContainerState.RUNNING.value == "running"
    assert ContainerState.STOPPED.value == "stopped"
    assert ContainerState.CREATED.value == "created"

    # Test ContainerHealth enum
    assert ContainerHealth.HEALTHY.value == "healthy"
    assert ContainerHealth.UNHEALTHY.value == "unhealthy"
    assert ContainerHealth.STARTING.value == "starting"


def test_container_config():
    """Test ContainerConfig model."""
    # Test minimal config
    config = ContainerConfig(image="nginx:latest")
    assert config.image == "nginx:latest"
    assert config.name is None
    assert config.environment == {}
    assert config.volumes == {}
    assert config.ports == {}

    # Test full config
    full_config = ContainerConfig(
        image="nginx:latest",
        name="test-container",
        labels={"kawaflow.flow_id": "123"},
        environment={"ENV": "test"},
        volumes={"/host": "/container"},
        ports={"80": 8080},
        command=["nginx", "-g", "daemon off;"],
        working_dir="/app",
    )
    assert full_config.name == "test-container"
    assert full_config.labels["kawaflow.flow_id"] == "123"
    assert full_config.environment["ENV"] == "test"
    assert full_config.volumes["/host"] == "/container"
    assert full_config.ports["80"] == 8080
    assert full_config.command == ["nginx", "-g", "daemon off;"]
    assert full_config.working_dir == "/app"

    # Test whitespace handling in validation
    trimmed_config = ContainerConfig(
        image="  nginx:latest  ", name="  test-container  "
    )
    assert trimmed_config.image == "nginx:latest"
    assert trimmed_config.name == "test-container"


def test_container_config_validation_errors():
    """Test ContainerConfig validation errors."""
    # Test empty image
    with pytest.raises(ValidationError) as exc_info:
        ContainerConfig(image="")
    assert "Image name cannot be empty" in str(exc_info.value)

    # Test whitespace-only image
    with pytest.raises(ValidationError) as exc_info:
        ContainerConfig(image="   ")
    assert "Image name cannot be empty" in str(exc_info.value)

    # Test empty name string (None is allowed, but empty string is not)
    with pytest.raises(ValidationError) as exc_info:
        ContainerConfig(image="nginx:latest", name="")
    assert "Container name cannot be empty string" in str(exc_info.value)

    # Test whitespace-only name
    with pytest.raises(ValidationError) as exc_info:
        ContainerConfig(image="nginx:latest", name="   ")
    assert "Container name cannot be empty string" in str(exc_info.value)


def test_container_info():
    """Test ContainerInfo model."""
    now = datetime.now()
    info = ContainerInfo(
        id="abc123",
        name="test-container",
        status="running",
        image="nginx:latest",
        created=now,
        socket_path="/tmp/abc123.sock",
        ports={"80": 8080},
        environment={"ENV": "test"},
    )

    assert info.id == "abc123"
    assert info.name == "test-container"
    assert info.status == "running"
    assert info.image == "nginx:latest"
    assert info.created == now
    assert info.socket_path == "/tmp/abc123.sock"


def test_container_status():
    """Test ContainerStatus model."""
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
    assert status.resource_usage["cpu"] == 25.5

    # Test with string enum values
    status_from_strings = ContainerStatus(
        id="abc123",
        state="running",  # String value should be converted to enum
        health="healthy",  # String value should be converted to enum
        socket_connected=True,
    )
    assert status_from_strings.state == ContainerState.RUNNING
    assert status_from_strings.health == ContainerHealth.HEALTHY


def test_container_status_enum_validation():
    """Test ContainerStatus enum validation."""
    # Test invalid state enum value
    with pytest.raises(ValidationError) as exc_info:
        ContainerStatus(
            id="abc123",
            state="invalid_state",  # Invalid state value
            health=ContainerHealth.HEALTHY,
            socket_connected=True,
        )
    assert "Invalid container state" in str(exc_info.value)

    # Test invalid health enum value
    with pytest.raises(ValidationError) as exc_info:
        ContainerStatus(
            id="abc123",
            state=ContainerState.RUNNING,
            health="invalid_health",  # Invalid health value
            socket_connected=True,
        )
    assert "Invalid container health" in str(exc_info.value)


def test_socket_message():
    """Test SocketMessage model."""
    message = SocketMessage(command="dump", data={"key": "value"})

    assert message.command == "dump"
    assert message.data["key"] == "value"
    assert isinstance(message.timestamp, datetime)

    # Test whitespace handling in validation
    trimmed_message = SocketMessage(command="  dump  ", data={"key": "value"})
    assert trimmed_message.command == "dump"


def test_socket_message_validation():
    """Test SocketMessage validation."""
    # Test empty command
    with pytest.raises(ValidationError) as exc_info:
        SocketMessage(command="", data={})
    assert "Command cannot be empty" in str(exc_info.value)

    # Test whitespace-only command
    with pytest.raises(ValidationError) as exc_info:
        SocketMessage(command="   ", data={})
    assert "Command cannot be empty" in str(exc_info.value)


def test_error_response():
    """Test ErrorResponse model."""
    error = ErrorResponse(
        error_type="docker_api_error",
        message="Container not found",
        details={"container_id": "abc123"},
    )

    assert error.error is True
    assert error.error_type == "docker_api_error"
    assert error.message == "Container not found"
    assert error.details["container_id"] == "abc123"
    assert isinstance(error.timestamp, datetime)

    # Test whitespace handling in validation
    trimmed_error = ErrorResponse(
        error_type="  docker_api_error  ",
        message="  Container not found  ",
        details={"container_id": "abc123"},
    )
    assert trimmed_error.error_type == "docker_api_error"
    assert trimmed_error.message == "Container not found"


def test_error_response_validation():
    """Test ErrorResponse validation."""
    # Test empty error_type
    with pytest.raises(ValidationError) as exc_info:
        ErrorResponse(error_type="", message="Container not found")
    assert "Error type cannot be empty" in str(exc_info.value)

    # Test whitespace-only error_type
    with pytest.raises(ValidationError) as exc_info:
        ErrorResponse(error_type="   ", message="Container not found")
    assert "Error type cannot be empty" in str(exc_info.value)

    # Test empty message
    with pytest.raises(ValidationError) as exc_info:
        ErrorResponse(error_type="docker_api_error", message="")
    assert "Error message cannot be empty" in str(exc_info.value)

    # Test whitespace-only message
    with pytest.raises(ValidationError) as exc_info:
        ErrorResponse(error_type="docker_api_error", message="   ")
    assert "Error message cannot be empty" in str(exc_info.value)


def test_socket_io_events():
    """Test Socket.IO event models."""
    # Test CreateContainer
    create_event = CreateContainer(
        image="nginx:latest",
        name="test",
        flow_id=42,
        flow_run_id=7,
        flow_name="demo",
        graph_hash="hash123",
        test_run_id="test-run",
        labels={"kawaflow.flow_name": "override"},
        environment={"ENV": "test"},
        volumes={"/host": "/container"},
        ports={"80": 8080},
    )
    assert create_event.image == "nginx:latest"
    assert create_event.name == "test"
    assert create_event.flow_id == 42
    assert create_event.flow_run_id == 7
    assert create_event.flow_name == "demo"
    assert create_event.graph_hash == "hash123"
    assert create_event.test_run_id == "test-run"
    assert create_event.labels["kawaflow.flow_name"] == "override"

    # Test ContainerOperation
    op_event = ContainerOperation(container_id="abc123")
    assert op_event.container_id == "abc123"

    # Test UpdateContainer
    update_event = UpdateContainer(
        container_id="abc123", code_path="/path/to/code"
    )
    assert update_event.container_id == "abc123"
    assert update_event.code_path == "/path/to/code"

    # Test SendMessage
    msg_event = SendMessage(
        container_id="abc123", message={"command": "dump", "data": {}}
    )
    assert msg_event.container_id == "abc123"
    assert msg_event.message["command"] == "dump"

    # Test ActivityLog
    activity_event = ActivityLog(
        activity_type="container_created",
        container_id="abc123",
        message="Container created successfully",
        details={"image": "nginx:latest"},
    )
    assert activity_event.activity_type == "container_created"
    assert activity_event.container_id == "abc123"
    assert activity_event.message == "Container created successfully"
    assert activity_event.details is not None
    assert activity_event.details.get("image") == "nginx:latest"
    assert isinstance(activity_event.timestamp, datetime)

    # Test whitespace handling in validation
    trimmed_create_event = CreateContainer(
        image="  nginx:latest  ", name="  test  "
    )
    assert trimmed_create_event.image == "nginx:latest"
    assert trimmed_create_event.name == "test"

    trimmed_op_event = ContainerOperation(container_id="  abc123  ")
    assert trimmed_op_event.container_id == "abc123"

    trimmed_update_event = UpdateContainer(
        container_id="  abc123  ", code_path="  /path/to/code  "
    )
    assert trimmed_update_event.container_id == "abc123"
    assert trimmed_update_event.code_path == "/path/to/code"

    trimmed_activity_event = ActivityLog(
        activity_type="  container_created  ",
        container_id="  abc123  ",
        message="  Container created successfully  ",
    )
    assert trimmed_activity_event.activity_type == "container_created"
    assert trimmed_activity_event.container_id == "abc123"
    assert trimmed_activity_event.message == "Container created successfully"


def test_create_container_event_validation():
    """Test CreateContainer validation."""
    # Test empty image
    with pytest.raises(ValidationError) as exc_info:
        CreateContainer(image="")
    assert "Image name cannot be empty" in str(exc_info.value)

    # Test whitespace-only image
    with pytest.raises(ValidationError) as exc_info:
        CreateContainer(image="   ")
    assert "Image name cannot be empty" in str(exc_info.value)

    # Test empty name string
    with pytest.raises(ValidationError) as exc_info:
        CreateContainer(image="nginx:latest", name="")
    assert "Container name cannot be empty string" in str(exc_info.value)

    # Test empty optional fields
    with pytest.raises(ValidationError) as exc_info:
        CreateContainer(image="nginx:latest", flow_name=" ")
    assert "Optional string fields cannot be empty" in str(exc_info.value)


def test_container_operation_event_validation():
    """Test ContainerOperation validation."""
    # Test empty container_id
    with pytest.raises(ValidationError) as exc_info:
        ContainerOperation(container_id="")
    assert "Container ID cannot be empty" in str(exc_info.value)

    # Test whitespace-only container_id
    with pytest.raises(ValidationError) as exc_info:
        ContainerOperation(container_id="   ")
    assert "Container ID cannot be empty" in str(exc_info.value)


def test_update_container_event_validation():
    """Test UpdateContainer validation."""
    # Test empty container_id
    with pytest.raises(ValidationError) as exc_info:
        UpdateContainer(container_id="", code_path="/path/to/code")
    assert "Container ID cannot be empty" in str(exc_info.value)

    # Test empty code_path
    with pytest.raises(ValidationError) as exc_info:
        UpdateContainer(container_id="abc123", code_path="")
    assert "Code path cannot be empty" in str(exc_info.value)


def test_send_message_event_validation():
    """Test SendMessage validation."""
    # Test empty container_id
    with pytest.raises(ValidationError) as exc_info:
        SendMessage(container_id="", message={"command": "dump"})
    assert "Container ID cannot be empty" in str(exc_info.value)


def test_activity_log_event_validation():
    """Test ActivityLog validation."""
    # Test empty activity_type
    with pytest.raises(ValidationError) as exc_info:
        ActivityLog(
            activity_type="",
            container_id="abc123",
            message="Container created successfully",
        )
    assert "Activity type cannot be empty" in str(exc_info.value)

    # Test empty container_id
    with pytest.raises(ValidationError) as exc_info:
        ActivityLog(
            activity_type="container_created",
            container_id="",
            message="Container created successfully",
        )
    assert "Container ID cannot be empty" in str(exc_info.value)

    # Test empty message
    with pytest.raises(ValidationError) as exc_info:
        ActivityLog(
            activity_type="container_created",
            container_id="abc123",
            message="",
        )
    assert "Activity message cannot be empty" in str(exc_info.value)
