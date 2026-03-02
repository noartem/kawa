# AGENTS

## Purpose
- This file guides automated agents working in this repo.
- Focus on safe, consistent edits and how to run checks.
- Prefer existing patterns over inventing new ones.
- Keep changes minimal and aligned with current module boundaries.

## Repo quick facts
- Python service, requires Python >=3.12 (`pyproject.toml`).
- Entrypoint: `main.py` (FastAPI app + uvicorn runner).
- Core modules: `container_manager.py`, `event_handler.py`, `messaging.py`,
  `socket_communication_handler.py`, `system_logger.py`, `models.py`.
- Messaging backends: RabbitMQ (default) or in-memory (tests).
- Tests live in `tests/` and use pytest + pytest-asyncio.

## Runtime configuration
- `MESSAGING_BACKEND` controls messaging backend (`rabbitmq` or `inmemory`).
- `RABBITMQ_URL` configures the RabbitMQ connection URL.
- `FLOW_MANAGER_COMMAND_QUEUE` sets the command queue name.
- `FLOW_MANAGER_RESPONSE_QUEUE` sets the response queue name.
- `FLOW_MANAGER_EVENT_EXCHANGE` sets the event exchange name.
- `KAWAFLOW_TEST_RUN_ID` is injected as a container label when set.
- Default socket directory: `/tmp/kawaflow/sockets`.

## Build, lint, and test commands
### Lint/format
- `ruff check .`
- `ruff check --fix .`  # only if autofix is desired
- `ruff format .`

### Tests (full suite)
- `python -m pytest`
- `pytest` (if venv is active)

### Tests (single test)
- `python -m pytest tests/test_container_manager.py`
- `python -m pytest tests/test_container_manager.py::TestContainerManager::test_create_container_success`
- `python -m pytest -k "create_container" tests/test_container_manager.py`

### Type checking (optional)
- `ty check .` (if using `ty`, listed in dev deps)
- `mypy .` (config present in `pyproject.toml`, tool not required by default)

### Run the app (for manual verification)
- `python main.py`
- `uvicorn main:app --host 0.0.0.0 --port 8000`

### Dependency sync (if using uv)
- `uv sync --dev` (uses `uv.lock`)
- `uv run <command>` to run tools inside the uv environment

## Code style and conventions
### Formatting
- Keep line length at 88 (Ruff config).
- Prefer one expression per line for complex comprehensions.
- Use blank lines between logical sections and between import groups.
- Run `ruff format` instead of manual alignment for long literals.

### Imports
- Order: standard library, third-party, local modules.
- Use absolute imports from repo root (e.g., `from container_manager import ContainerManager`).
- Avoid relative imports; the codebase is flat (modules at repo root).
- Avoid wildcard imports; be explicit.

### Typing
- Use type hints for public functions and methods.
- Prefer `typing` generics (`Dict`, `List`, `Optional`, `Any`) to keep style consistent.
- For async APIs, annotate the concrete return type (e.g., `async def foo() -> None`).
- Use `Optional[...]` instead of `| None` in files that already use `Optional`.

### Naming
- Classes: `PascalCase`.
- Functions/variables: `snake_case`.
- Constants: `UPPER_SNAKE_CASE`.
- Use explicit names for IDs (`container_id`, `flow_run_id`) and booleans
  (`is_`, `has_`, `should_` prefixes).

### Docstrings and comments
- Public classes and significant methods use docstrings.
- Keep docstrings short and action-focused.
- Only add comments when behavior is non-obvious or fragile.

### Pydantic models
- Use `Field(..., description="...")` for public-facing fields.
- Use `default_factory` for `dict`/`list` defaults.
- Use `@field_validator` + `@classmethod` for validation and normalization.
- Store timestamps as `datetime` and serialize with `.isoformat()`.

### Error handling
- Catch narrow exceptions where possible, log with `SystemLogger.error`, then re-raise.
- Convert known errors into typed responses in `EventHandler._emit_error`.
- Preserve the original exception as the cause where appropriate (`raise ... from exc`).
- Use custom socket exceptions (`SocketConnectionError`, `SocketTimeoutError`,
  `SocketCommunicationError`) when working with sockets.

### Logging
- Use `SystemLogger` for structured logs; include a context dict with
  `operation`, identifiers, and relevant metadata.
- Do not log secrets; use `SensivityFilter` before emitting user activity logs.
- For user-facing events, prefer publishing events via `Messaging`.
- Logging should never raise; follow the defensive pattern in `SystemLogger`.

### Async and background tasks
- Use `asyncio.create_task` for long-running tasks and track cancellation.
- Handle `asyncio.CancelledError` explicitly in loops.
- Avoid blocking I/O on the event loop; use executors for socket operations.
- Use `asyncio.sleep` in loops to back off after failures.

### Data and messaging
- Event payloads are plain dicts; model inputs with Pydantic before use.
- Use `Messaging.publish_event` for domain events and `publish_response` for RPC replies.
- Keep error responses consistent with `ErrorResponse`.
- Include `correlation_id` and `reply_to` when available.

## Docker and sockets
- Docker SDK is accessed via `docker.from_env`; patch it in tests.
- Container sockets are named `{container_name}.sock` under `/tmp/kawaflow/sockets`.
- Containers mount the socket at `/var/run/kawaflow.sock`.
- Container update mounts code at `/app` and preserves the socket bind.
- Health checks and resource monitoring run in background loops.

## Messaging payloads
- Commands are dicts with `action`, `data`, optional `reply_to`, `correlation_id`.
- Success responses use `{"ok": True, "action": ..., "data": ...}`.
- Errors use `ErrorResponse` with `error=True`, `error_type`, `message`, `details`.
- Event routing keys default to `event.<event_name>`.
- Activity logs are published as `activity_log` events.

## File layout
- Modules are at the repo root; keep new modules at the same level unless needed.
- Test files go in `tests/` and must start with `test_`.
- `bin/` contains helper scripts to start/stop/restart the service and view logs.
- Reuse existing helpers (`InMemoryMessaging`, fixtures in `tests/conftest.py`).

## Testing guidelines
- Use pytest fixtures for shared setup and mocks.
- Use `@pytest.mark.asyncio` for async tests.
- Use `AsyncMock` for async dependencies and `Mock/MagicMock` with `spec` where possible.
- Prefer `tmp_path` for filesystem tests and `patch` for external systems (Docker, OS).
- Do not hit real Docker or RabbitMQ in unit tests; mock `docker.from_env` and messaging.
- Keep tests deterministic; avoid reliance on wall-clock timing.

## Security and safety
- Treat any message payloads as potentially sensitive; filter before logging.
- Avoid including tokens/credentials in logs or test fixtures.
- Use `SensivityFilter.check_data` to decide if a preview is safe.

## Repository rules
- No `.cursor/rules`, `.cursorrules`, or `.github/copilot-instructions.md` found in this repo.
