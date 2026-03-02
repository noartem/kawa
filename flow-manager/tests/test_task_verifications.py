"""
Lightweight verification of core components after RabbitMQ migration.
"""

from unittest.mock import Mock, patch


from container_manager import ContainerManager
from event_handler import EventHandler
from messaging import InMemoryMessaging
from models import ContainerConfig, ContainerHealth, ContainerState
from socket_communication_handler import SocketCommunicationHandler
from system_logger import SystemLogger
from user_activity_logger import UserActivityLogger


class TestTaskVerification:
    def test_data_models_exist(self):
        config = ContainerConfig(image="test:latest")
        assert config.image == "test:latest"
        assert hasattr(ContainerState, "RUNNING")
        assert hasattr(ContainerHealth, "HEALTHY")

    def test_system_logger_methods(self):
        logger = SystemLogger("test_logger")
        assert hasattr(logger, "container_operation")
        assert hasattr(logger, "error")
        assert hasattr(logger, "debug")

    def test_user_activity_logger_methods(self):
        logger = UserActivityLogger(InMemoryMessaging(logger=Mock(spec=SystemLogger)))
        assert hasattr(logger, "container_created")
        assert hasattr(logger, "container_message")
        assert hasattr(logger, "container_error")

    def test_container_manager_methods(self):
        with patch("docker.from_env"):
            manager = ContainerManager(logger=Mock(spec=SystemLogger))
            assert hasattr(manager, "create_container")
            assert hasattr(manager, "start_container")
            assert hasattr(manager, "stop_container")
            assert hasattr(manager, "restart_container")
            assert hasattr(manager, "delete_container")

    def test_socket_communication_handler_methods(self, tmp_path):
        handler = SocketCommunicationHandler(socket_dir=str(tmp_path), logger=Mock())
        assert hasattr(handler, "setup_socket")
        assert hasattr(handler, "cleanup_socket")
        assert hasattr(handler, "send_message")
        assert hasattr(handler, "receive_message")

    def test_event_handler_methods(self):
        handler = EventHandler(
            messaging=InMemoryMessaging(logger=Mock(spec=SystemLogger)),
            container_manager=Mock(spec=ContainerManager),
            socket_handler=Mock(spec=SocketCommunicationHandler),
            logger=Mock(spec=SystemLogger),
            user_logger=Mock(spec=UserActivityLogger),
        )
        assert hasattr(handler, "handle_create_container")
        assert hasattr(handler, "handle_send_message")
