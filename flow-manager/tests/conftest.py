"""
Pytest configuration and shared fixtures for flow-manager tests.
"""

import tempfile
import pytest
from unittest.mock import Mock, patch

from container_manager import ContainerManager
from messaging import InMemoryMessaging
from socket_communication_handler import SocketCommunicationHandler
from system_logger import SystemLogger
from user_activity_logger import UserActivityLogger


@pytest.fixture
def mock_docker_client():
    """Create a mock Docker client for testing."""
    client = Mock()
    client.containers = Mock()
    client.images = Mock()
    return client


@pytest.fixture
def container_manager(mock_docker_client, mock_logger):
    """Create a ContainerManager instance with mocked Docker client."""
    with patch("docker.from_env", return_value=mock_docker_client):
        manager = ContainerManager(logger=mock_logger)
        manager.docker_client = mock_docker_client
        return manager


@pytest.fixture
def mock_container():
    """Create a mock Docker container object."""
    container = Mock()
    container.id = "test-container-id"
    container.name = "test-container"
    container.status = "running"
    container.image.tags = ["test-image:latest"]
    container.attrs = {
        "Created": "2023-01-01T00:00:00.000000000Z",
        "State": {"Status": "running", "Health": {"Status": "healthy"}},
        "NetworkSettings": {"Ports": {"8080/tcp": [{"HostPort": "8080"}]}},
        "Config": {"Env": ["TEST_VAR=test_value"]},
    }
    return container


@pytest.fixture
def temp_socket_dir():
    """Create temporary directory for socket files."""
    with tempfile.TemporaryDirectory() as temp_dir:
        yield temp_dir


@pytest.fixture
def mock_logger():
    """Create mock system logger."""
    return Mock(spec=SystemLogger)


@pytest.fixture
def socket_handler(temp_socket_dir, mock_logger):
    """Create SocketCommunicationHandler instance."""
    return SocketCommunicationHandler(socket_dir=temp_socket_dir, logger=mock_logger)


@pytest.fixture
def messaging(mock_logger):
    """In-memory messaging backend for tests."""
    return InMemoryMessaging(logger=mock_logger)


@pytest.fixture
def activity_logger(messaging):
    """Create a UserActivityLogger instance with in-memory messaging."""
    return UserActivityLogger(messaging)


@pytest.fixture
def app_instance():
    """Create a test application instance with mocked Docker."""
    with (
        patch("docker.from_env") as mock_docker,
        patch.dict("os.environ", {"MESSAGING_BACKEND": "inmemory"}),
    ):
        mock_docker.return_value = Mock()
        # Import here to avoid Docker connection during module import
        from main import FlowManagerApp

        return FlowManagerApp(socket_dir="/tmp/test_sockets")
