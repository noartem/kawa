import asyncio
import os
import signal
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, HTTPException

from container_manager import ContainerManager
from messaging import create_messaging
from event_handler import EventHandler
from sensivity_filter import SensivityFilter
from socket_communication_handler import (
    SocketCommunicationError,
    SocketCommunicationHandler,
    SocketConnectionError,
    SocketTimeoutError,
)
from system_logger import SystemLogger
from user_activity_logger import UserActivityLogger


def _load_env_file(env_path: Path) -> None:
    if not env_path.exists():
        return

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key and key not in os.environ:
            os.environ[key] = value


_load_env_file(Path(__file__).with_name(".env"))

DEFAULT_SOCKET_DIR = os.getenv("FLOW_MANAGER_SOCKET_DIR", "/tmp/kawaflow/sockets")


class FlowManagerApp:
    def __init__(self, socket_dir: str = DEFAULT_SOCKET_DIR):
        self.socket_dir = socket_dir
        self.logger = SystemLogger("flow_manager")
        self._messaging_backend_env = os.getenv("MESSAGING_BACKEND")
        self.messaging_kind = self._messaging_backend_env or "rabbitmq"
        self.messaging = create_messaging(
            kind=self.messaging_kind,
            logger=SystemLogger(f"messaging_{self.messaging_kind}"),
        )
        self.container_manager = ContainerManager(
            logger=SystemLogger("container_manager"), socket_dir=socket_dir
        )
        self.socket_handler = SocketCommunicationHandler(
            socket_dir=socket_dir, logger=SystemLogger("socket_communication_handler")
        )
        self.sensivity_filter = SensivityFilter()
        self.user_logger = UserActivityLogger(
            self.messaging, sensivity_filter=self.sensivity_filter
        )
        self.event_handler = EventHandler(
            messaging=self.messaging,
            container_manager=self.container_manager,
            socket_handler=self.socket_handler,
            logger=SystemLogger("event_handler"),
            user_logger=self.user_logger,
        )

        self._shutdown_event = asyncio.Event()
        self._background_tasks = []
        self._shutdown_lock = asyncio.Lock()
        self._started = False

        self.logger.debug(
            "FlowManagerApp initialized",
            {"socket_dir": socket_dir, "messaging": self.messaging_kind},
        )

    def _swap_messaging_backend(self, kind: str) -> None:
        self.messaging_kind = kind
        self.messaging = create_messaging(
            kind=kind,
            logger=SystemLogger(f"messaging_{kind}"),
        )
        self.user_logger = UserActivityLogger(
            self.messaging, sensivity_filter=self.sensivity_filter
        )
        self.event_handler.messaging = self.messaging
        self.event_handler.user_logger = self.user_logger

    async def startup(self) -> None:
        """Initialize application components and start background tasks."""
        if self._started:
            self.logger.debug("Startup requested for already running app", {})
            return

        self.logger.debug("Starting Flow Manager application", {})
        os.makedirs(self.socket_dir, exist_ok=True)
        self._shutdown_event.clear()

        try:
            await self.messaging.connect()
        except Exception as exc:
            if (
                self._messaging_backend_env is None
                and self.messaging_kind == "rabbitmq"
            ):
                self.logger.error(
                    exc,
                    {
                        "operation": "messaging_connect",
                        "fallback": "inmemory",
                    },
                )
                self._swap_messaging_backend("inmemory")
                await self.messaging.connect()
            else:
                raise
        await self.container_manager.start_monitoring()
        await self.event_handler.start()

        self._background_tasks.append(asyncio.create_task(self._health_check_loop()))
        self._started = True

        self.logger.debug(
            "Flow Manager application started",
            {"background_tasks": len(self._background_tasks)},
        )

    async def shutdown(self) -> None:
        async with self._shutdown_lock:
            if not self._started:
                return

            self._started = False
            self.logger.debug("Shutting down Flow Manager application", {})
            self._shutdown_event.set()

            for task in self._background_tasks:
                if not task.done():
                    task.cancel()
            if self._background_tasks:
                await asyncio.gather(*self._background_tasks, return_exceptions=True)
            self._background_tasks = []

            await self.container_manager.stop_monitoring()
            await self.socket_handler.close_all_connections()
            await self.messaging.close()

            self.logger.debug("Flow Manager application shutdown complete", {})

    async def _health_check_loop(self) -> None:
        while not self._shutdown_event.is_set():
            try:
                await asyncio.sleep(30)
                if self._shutdown_event.is_set():
                    break

                containers = await self.container_manager.list_containers()
                for container in containers:
                    try:
                        status = await self.container_manager.get_container_status(
                            container.id
                        )
                        state = getattr(status.state, "value", status.state)
                        health = getattr(status.health, "value", status.health)
                        await self.messaging.publish_event(
                            "container_status_update",
                            {
                                "container_id": container.id,
                                "status": {
                                    "state": state,
                                    "health": health,
                                    "socket_connected": status.socket_connected,
                                    "uptime": str(status.uptime)
                                    if status.uptime
                                    else None,
                                    "resource_usage": status.resource_usage,
                                },
                            },
                        )
                    except Exception as exc:
                        self.logger.error(
                            exc,
                            {
                                "operation": "health_check",
                                "container_id": container.id,
                            },
                        )
            except asyncio.CancelledError:
                break
            except Exception as exc:
                self.logger.error(exc, {"operation": "health_check_loop"})
                await asyncio.sleep(5)


app_instance = FlowManagerApp()


@asynccontextmanager
async def lifespan(app: FastAPI):
    """FastAPI lifespan context manager for startup and shutdown."""
    await app_instance.startup()

    def signal_handler(signum, frame):
        asyncio.create_task(app_instance.shutdown())

    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)

    try:
        yield
    finally:
        await app_instance.shutdown()


app = FastAPI(
    title="Flow Manager",
    description="Container lifecycle management with RabbitMQ integration",
    version="1.0.0",
    lifespan=lifespan,
)


@app.get("/health")
async def health_check():
    """Health check endpoint for monitoring."""
    return {
        "status": "healthy",
        "service": "flow-manager",
        "socket_dir": app_instance.socket_dir,
        "components": {
            "container_manager": "active",
            "socket_handler": "active",
            "event_handler": "active",
            "messaging": app_instance.messaging_kind,
        },
    }


@app.get("/containers/{container_id}/graph")
async def container_graph(container_id: str):
    """Request the runtime actor graph from a running flow container."""
    try:
        await app_instance.socket_handler.send_message(
            container_id, {"command": "dump", "data": {}}
        )
        graph = await app_instance.socket_handler.receive_message(
            container_id, timeout=10
        )
    except (SocketConnectionError, SocketTimeoutError, SocketCommunicationError) as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc
    return {"container_id": container_id, "graph": graph}


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=8000,
        reload=False,
        log_level="info",
    )
