"""
Tests for ContainerManager class.

This module tests the core container lifecycle management functionality
including creation, start/stop operations, and cleanup.
"""

import os
import json
from unittest.mock import MagicMock, Mock, patch

import pytest
from docker.errors import APIError, ImageNotFound, NotFound

from container_manager import ContainerManager
from models import ContainerConfig, ContainerInfo, ContainerState, ContainerStatus
from system_logger import SystemLogger


@pytest.fixture
def mock_docker_client():
    """Create a mock Docker client for testing."""
    client = MagicMock()
    client.containers = MagicMock()
    client.images = MagicMock()
    return client


@pytest.fixture
def container_manager(mock_docker_client):
    """Create a ContainerManager instance with mocked Docker client."""
    with patch("docker.from_env", return_value=mock_docker_client):
        manager = ContainerManager(logger=Mock(spec=SystemLogger))
        manager.docker_client = mock_docker_client
        return manager


@pytest.fixture
def sample_container_config():
    """Create a sample container configuration for testing."""
    return ContainerConfig(
        image="test-image:latest",
        name="test-container",
        labels={"kawaflow.flow_id": "123"},
        environment={"TEST_VAR": "test_value"},
        volumes={"/host/path": "/container/path"},
        ports={"8080": 8080},
    )


@pytest.fixture
def mock_container():
    """Create a mock Docker container object."""
    container = MagicMock()
    container.id = "test-container-id"
    container.name = "test-container"
    container.status = "running"

    # Mock image with tags
    mock_image = MagicMock()
    mock_image.tags = ["test-image:latest"]
    mock_image.id = "test-image-id"
    container.image = mock_image

    # Create a proper attrs structure with all required fields
    container.attrs = {
        "Created": "2023-01-01T00:00:00.000000000+00:00",  # ISO format with timezone
        "State": {
            "Status": "running",
            "Health": {"Status": "healthy"},
            "StartedAt": "2023-01-01T00:00:00.000000000+00:00",
        },
        "NetworkSettings": {"Ports": {"8080/tcp": [{"HostPort": "8080"}]}},
        "Config": {"Env": ["TEST_VAR=test_value"]},
        "HostConfig": {
            "Binds": [
                "/host/path:/container/path",
                "/tmp/kawaflow/sockets/test-container:/run",
            ]
        },
    }
    return container


class TestContainerManager:
    """Test cases for ContainerManager class."""

    @pytest.mark.asyncio
    async def test_create_container_success(
        self, container_manager, sample_container_config, mock_container
    ):
        """Test successful container creation."""
        # Setup
        container_manager.docker_client.containers.create.return_value = mock_container

        # Execute
        result = await container_manager.create_container(sample_container_config)

        # Assert
        assert isinstance(result, ContainerInfo)
        assert result.id == "test-container-id"
        assert result.name == "test-container"
        assert result.status == "running"
        assert (
            container_manager._container_states["test-container-id"]
            == ContainerState.CREATED
        )

        # Verify Docker client was called correctly
        container_manager.docker_client.containers.create.assert_called_once()
        _, kwargs = container_manager.docker_client.containers.create.call_args
        assert kwargs["labels"]["kawaflow.flow_id"] == "123"

    @pytest.mark.asyncio
    async def test_create_container_image_not_found(
        self, container_manager, sample_container_config
    ):
        """Test container creation with missing image."""
        # Setup
        container_manager.docker_client.containers.create.side_effect = ImageNotFound(
            "Image not found"
        )

        # Execute & Assert
        with pytest.raises(ImageNotFound):
            await container_manager.create_container(sample_container_config)

    @pytest.mark.asyncio
    async def test_get_container_graph_success(self, container_manager, mock_container):
        """Test extracting graph payload from container exec output."""
        container_manager.docker_client.containers.get.return_value = mock_container

        graph_payload = {
            "events": [
                {
                    "id": "Cron",
                    "name": "Cron",
                    "source_line": 4,
                    "source_kind": "import",
                    "source_module": "flows.events",
                }
            ],
            "actors": [
                {
                    "id": "Starter",
                    "name": "Starter",
                    "receives": [],
                    "source_line": 16,
                    "source_kind": "main",
                    "source_module": None,
                }
            ],
            "nodes": [
                {
                    "id": "Cron",
                    "type": "event",
                    "label": "Cron",
                    "source_line": 4,
                    "source_kind": "import",
                    "source_module": "flows.events",
                },
                {
                    "id": "Starter",
                    "type": "actor",
                    "label": "Starter",
                    "source_line": 16,
                    "source_kind": "main",
                    "source_module": None,
                },
            ],
            "edges": [],
        }
        mock_container.exec_run.return_value = MagicMock(
            exit_code=0,
            output=json.dumps(graph_payload).encode("utf-8"),
        )

        result = await container_manager.get_container_graph("test-container-id")

        assert result == graph_payload
        mock_container.exec_run.assert_called_once()

    @pytest.mark.asyncio
    async def test_start_container_success(self, container_manager, mock_container):
        """Test successful container start."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container

        # Execute
        await container_manager.start_container("test-container-id")

        # Assert
        mock_container.start.assert_called_once()

    @pytest.mark.asyncio
    async def test_start_container_not_found(self, container_manager):
        """Test starting non-existent container."""
        # Setup
        container_manager.docker_client.containers.get.side_effect = NotFound(
            "Container not found"
        )

        # Execute & Assert
        with pytest.raises(NotFound):
            await container_manager.start_container("non-existent-id")

    @pytest.mark.asyncio
    async def test_stop_container_success(self, container_manager, mock_container):
        """Test successful container stop."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container

        # Execute
        await container_manager.stop_container("test-container-id")

        # Assert
        mock_container.stop.assert_called_once()

    @pytest.mark.asyncio
    async def test_restart_container_success(self, container_manager, mock_container):
        """Test successful container restart."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container

        # Execute
        await container_manager.restart_container("test-container-id")

        # Assert
        mock_container.restart.assert_called_once()

    @pytest.mark.asyncio
    async def test_delete_container_success(self, container_manager, mock_container):
        """Test successful container deletion."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container
        mock_container.status = "stopped"

        with (
            patch("os.path.exists", return_value=True),
            patch("shutil.rmtree") as mock_rmtree,
        ):
            # Execute
            await container_manager.delete_container("test-container-id")

            # Assert
            mock_container.remove.assert_called_once()
            mock_rmtree.assert_called_once()

    @pytest.mark.asyncio
    async def test_delete_running_container(self, container_manager, mock_container):
        """Test deletion of running container (should stop first)."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container
        mock_container.status = "running"

        with patch("os.path.exists", return_value=True), patch("os.remove"):
            # Execute
            await container_manager.delete_container("test-container-id")

            # Assert
            mock_container.stop.assert_called_once()
            mock_container.remove.assert_called_once()

    @pytest.mark.asyncio
    async def test_get_container_status_success(
        self, container_manager, mock_container
    ):
        """Test successful container status retrieval."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container

        with patch("os.path.exists", return_value=True):
            # Execute
            result = await container_manager.get_container_status("test-container-id")

            # Assert
            assert isinstance(result, ContainerStatus)
            assert result.id == "test-container-id"
            assert result.state == ContainerState.RUNNING
            assert result.socket_connected is True

    @pytest.mark.asyncio
    async def test_list_containers_success(self, container_manager, mock_container):
        """Test successful container listing."""
        # Setup
        container_manager.docker_client.containers.list.return_value = [mock_container]

        # Execute
        result = await container_manager.list_containers()

        # Assert
        assert len(result) == 1
        assert isinstance(result[0], ContainerInfo)
        assert result[0].id == "test-container-id"

    @pytest.mark.asyncio
    async def test_update_container_success(
        self, container_manager, mock_container, tmp_path
    ):
        """Test successful container code update."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container
        mock_container.status = "running"

        # Mock the new container after update
        new_container = MagicMock()
        new_container.id = "new-test-container-id"
        new_container.name = "test-container"
        container_manager.docker_client.containers.create.return_value = new_container

        # Create temporary directories for testing
        code_path = tmp_path / "code"
        code_path.mkdir()

        # Create some test files in the code path
        test_files = ["file1.py", "file2.py", "config.json"]
        for file_name in test_files:
            (code_path / file_name).write_text(f"Content of {file_name}")

        # Create a subdirectory with files
        subdir = code_path / "subdir"
        subdir.mkdir()
        (subdir / "submodule.py").write_text("# Submodule content")

        # Mock os.listdir to return actual directory contents
        def mock_listdir(path):
            if str(path) == str(code_path):
                return ["file1.py", "file2.py", "config.json", "subdir"]
            elif str(path) == str(subdir):
                return ["submodule.py"]
            return []

        with (
            patch("os.path.exists", return_value=True),
            patch("os.listdir", side_effect=mock_listdir),
            patch("os.remove"),
            patch("os.path.isdir", side_effect=lambda p: "subdir" in str(p)),
            patch("shutil.rmtree"),
            patch("shutil.copytree"),
            patch("shutil.copy2"),
            patch("os.makedirs"),
        ):
            # Execute
            await container_manager.update_container(
                "test-container-id", str(code_path)
            )

            # Assert
            mock_container.remove.assert_called_once()
            container_manager.docker_client.containers.create.assert_called_once()

    @pytest.mark.asyncio
    async def test_update_container_with_error_and_rollback(
        self, container_manager, mock_container, tmp_path
    ):
        """Test container update with error and rollback."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container
        mock_container.status = "running"

        # Create temporary directories for testing
        code_path = tmp_path / "code"
        code_path.mkdir()
        backup_path = tmp_path / "backup"
        backup_path.mkdir()

        # Create some test files
        (code_path / "file1.py").write_text("Content of file1.py")
        (code_path / "file2.py").write_text("Content of file2.py")

        # Mock container.remove to raise an exception to trigger rollback
        mock_container.remove.side_effect = Exception("Container removal failed")

        # Mock os.listdir to return actual directory contents
        def mock_listdir(path):
            if str(path) == str(code_path):
                return ["file1.py", "file2.py"]
            return []

        with (
            patch("os.path.exists", return_value=True),
            patch("os.listdir", side_effect=mock_listdir),
            patch("os.path.isdir", return_value=False),
            patch("shutil.rmtree"),
            patch("shutil.copytree"),
            patch("shutil.copy2"),
            patch("os.makedirs"),
        ):
            # Execute & Assert
            with pytest.raises(Exception):
                await container_manager.update_container(
                    "test-container-id", str(code_path)
                )

            # Verify rollback was attempted
            mock_container.remove.assert_called_once()

    @pytest.mark.asyncio
    async def test_socket_path_generation(self, container_manager):
        """Test socket path generation for containers."""
        # Execute
        container_id = "test-container-id"
        socket_path = os.path.join(
            container_manager.socket_dir, container_id, "kawaflow.sock"
        )

        # Assert
        assert socket_path.endswith("test-container-id/kawaflow.sock")
        assert "/tmp/kawaflow" in socket_path

    @pytest.mark.asyncio
    async def test_container_info_conversion(self, container_manager, mock_container):
        """Test conversion of Docker container to ContainerInfo."""
        # Execute
        socket_path = "/tmp/kawaflow/sockets/test-container/kawaflow.sock"
        result = container_manager._build_container_info(mock_container, socket_path)

        # Assert
        assert isinstance(result, ContainerInfo)
        assert result.id == "test-container-id"
        assert result.name == "test-container"
        assert result.status == "running"
        assert result.image == "test-image:latest"
        assert result.socket_path == socket_path

    @pytest.mark.asyncio
    async def test_error_handling_api_error(self, container_manager):
        """Test handling of Docker API errors."""
        # Setup
        container_manager.docker_client.containers.get.side_effect = APIError(
            "API Error"
        )

        # Execute & Assert
        with pytest.raises(APIError):
            await container_manager.start_container("test-container-id")

    @pytest.mark.asyncio
    async def test_socket_cleanup_on_delete(self, container_manager, mock_container):
        """Test that socket files are cleaned up on container deletion."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container
        mock_container.status = "stopped"

        with (
            patch("os.path.exists", return_value=True) as mock_exists,
            patch("shutil.rmtree") as mock_rmtree,
        ):
            # Execute
            await container_manager.delete_container("test-container-id")

            # Assert
            mock_exists.assert_called()
            mock_rmtree.assert_called()

    @pytest.mark.asyncio
    async def test_update_container_nonexistent_code_path(
        self, container_manager, mock_container
    ):
        """Test container update with non-existent code path."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container

        with patch("os.path.exists", return_value=False):
            # Execute & Assert
            with pytest.raises(FileNotFoundError):
                await container_manager.update_container(
                    "test-container-id", "/nonexistent/code/path"
                )

    @pytest.mark.asyncio
    async def test_update_container_with_nested_directories(
        self, container_manager, mock_container, tmp_path
    ):
        """Test container update with nested directory structure."""
        # Setup
        container_manager.docker_client.containers.get.return_value = mock_container
        mock_container.status = "stopped"  # Test with stopped container

        # Mock the new container after update
        new_container = MagicMock()
        new_container.id = "new-test-container-id"
        new_container.name = "test-container"
        container_manager.docker_client.containers.create.return_value = new_container

        # Create temporary directory structure for testing
        code_path = tmp_path / "complex_code"
        code_path.mkdir()

        # Create main files
        (code_path / "main.py").write_text("# Main module")
        (code_path / "config.json").write_text('{"version": "1.0.0"}')

        # Create nested directories with files
        src_dir = code_path / "src"
        src_dir.mkdir()
        (src_dir / "__init__.py").write_text("")
        (src_dir / "module.py").write_text("# Module code")

        tests_dir = code_path / "tests"
        tests_dir.mkdir()
        (tests_dir / "test_main.py").write_text("# Test code")

        # Create deeper nested directory
        utils_dir = src_dir / "utils"
        utils_dir.mkdir()
        (utils_dir / "__init__.py").write_text("")
        (utils_dir / "helpers.py").write_text("# Helper functions")

        # Mock directory structure for os.listdir and os.path.isdir
        def mock_listdir(path):
            path_str = str(path)
            if path_str == str(code_path):
                return ["main.py", "config.json", "src", "tests"]
            elif path_str == str(src_dir):
                return ["__init__.py", "module.py", "utils"]
            elif path_str == str(tests_dir):
                return ["test_main.py"]
            elif path_str == str(utils_dir):
                return ["__init__.py", "helpers.py"]
            return []

        def mock_isdir(path):
            path_str = str(path)
            return (
                "src" in path_str
                and path_str.endswith("src")
                or "tests" in path_str
                and path_str.endswith("tests")
                or "utils" in path_str
                and path_str.endswith("utils")
            )

        with (
            patch("os.path.exists", return_value=True),
            patch("os.listdir", side_effect=mock_listdir),
            patch("os.path.isdir", side_effect=mock_isdir),
            patch("os.remove"),
            patch("shutil.rmtree"),
            patch("shutil.copytree"),
            patch("shutil.copy2"),
            patch("os.makedirs"),
        ):
            # Execute
            await container_manager.update_container(
                "test-container-id", str(code_path)
            )

            # Assert
            mock_container.remove.assert_called_once()
            container_manager.docker_client.containers.create.assert_called_once()
            # Since container was stopped, we shouldn't start the new one
            new_container.start.assert_not_called()
