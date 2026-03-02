# AGENTS

This file guides agentic coding tools working in this repository.
Follow existing conventions and keep changes minimal.

## Repo map

- `kawa/`: Python actors library.
- `flow/`: Container for actors.
- `flow-manager/`: FastAPI service (Python >=3.12).
- `ui/`: Laravel 12 + Inertia Vue 3 frontend (Vite, Tailwind).
- `e2e/`: Python end-to-end tests.

## Build, lint, and test commands

### UI (Laravel + Vue) in `ui/`

- Install: `composer install` then `npm install`.
- Dev server: `composer run dev` (runs PHP server, queue, pail, and Vite).
- Build assets: `npm run build` (SSR build: `npm run build:ssr`).
- Lint: `npm run lint` (ESLint + Vue/TS rules).
- Format: `npm run format` (Prettier) or `npm run format:check`.
- PHP tests: `php artisan test --compact`.
- Single PHP test:
  - File: `php artisan test --compact tests/Feature/Auth/RegistrationTest.php`
  - Filter: `php artisan test --compact --filter=test_user_can_register`
- PHP format: `vendor/bin/pint --dirty`.

### Flow manager (FastAPI) in `flow-manager/`

- Run app: `python main.py` or `uvicorn main:app --host 0.0.0.0 --port 8000`.
- Lint/format: `ruff check .` and `ruff format .`.
- Tests: `python -m pytest`.
- Single test:
  - File: `python -m pytest tests/test_container_manager.py`
  - Node: `python -m pytest tests/test_container_manager.py::TestContainerManager::test_create_container_success`

### Kawa package in `kawa/`

- Tests: `python -m pytest` (run from `kawa/`).
- Single test:
  - File: `python -m pytest tests/test_core.py`
  - Node: `python -m pytest tests/test_core.py::test_event_definition`

### E2E tests in `e2e/`

- Tests: `python -m pytest` (uses `addopts = -q`).
- Single test:
  - File: `python -m pytest tests/test_flow_lifecycle.py`
  - Node: `python -m pytest tests/test_flow_lifecycle.py::test_flow_lifecycle`

## Code style and conventions

### Global

- Follow sibling files for structure and naming before adding new patterns.
- Prefer small, focused changes; avoid adding new base directories.
- Do not change dependencies without approval.
- Keep commits and diffs minimal; avoid drive-by refactors.
- For system wide issues, fix `.sandbox/compose.yml` and `.sandbox/Dockerfile` first.

### PHP/Laravel (applies in `ui/`)

- Use curly braces even for single-line control structures.
- Always include explicit return types and parameter type hints.
- Use PHP 8 constructor property promotion when possible.
- Avoid empty public constructors with zero parameters.
- Prefer PHPDoc blocks over inline comments; only comment complex logic.
- Use Form Requests for validation (no inline controller validation).
- Prefer Eloquent relationships over raw queries; avoid `DB::`.
- Use `config()` outside config files; never call `env()` in app code.
- Tests are required for changes; run a targeted `php artisan test --compact`.
- Error handling: prefer framework exceptions and validation errors; avoid silent failures.

### JavaScript/TypeScript/Vue (applies in `ui/`)

- Formatting is controlled by Prettier (`.prettierrc`): 4-space tabs, 80 cols.
- Imports are organized by `prettier-plugin-organize-imports`.
- Tailwind class ordering uses `prettier-plugin-tailwindcss`.
- Vue components must have a single root element.
- Prefer explicit prop/emit types; avoid `any` unless already used nearby.

### Python (general)

- Use 4-space indentation and keep imports grouped (stdlib, third-party, local).
- Prefer explicit type hints for public APIs; follow existing Optional/Union usage.
- Keep function and variable names in `snake_case`, classes in `PascalCase`.
- Keep tests deterministic; use pytest fixtures and mocks where possible.
- Avoid side effects at import time; keep module top-level light.
- Use `pathlib.Path` when handling filesystem paths unless existing code uses `os.path`.
- Error handling: catch narrow exceptions, log context, re-raise with `raise ... from exc`.

### Flow-manager specific (from `flow-manager/AGENTS.md`)

- Line length is 88 (Ruff config).
- Use absolute imports from repo root (flat module layout).
- Avoid wildcard imports; be explicit.
- Prefer `Optional[...]` where used in existing files.
- Logging uses `SystemLogger` and must not include secrets.
- Error handling: catch narrow exceptions, log, re-raise with `raise ... from exc`.
- Messaging: use `publish_event` for events and `publish_response` for RPC replies.
- Async: use `asyncio.create_task`, handle `asyncio.CancelledError` explicitly.
- Tests use `pytest` + `pytest-asyncio`; mark async tests with `@pytest.mark.asyncio`.
- Do not hit real Docker or RabbitMQ in unit tests; mock external clients.
- Use `ErrorResponse` for structured failures; include `correlation_id` when available.

### Kawa package (`kawa/`)

- The library is lightweight; keep APIs small and focused.
- Use dataclasses for simple data containers when adding new event classes.
- Keep decorators (`@event`, `@actor`) simple and deterministic.
- Avoid adding new runtime dependencies without approval.

### E2E tests

- Use pytest in `e2e/`; helpers live in `e2e/tests/helpers` and fixtures in `e2e/tests/fixtures`.
- Keep E2E tests stable; avoid brittle timing where possible.
- Prefer retry/backoff helpers for eventual consistency instead of raw sleeps.
- Keep test data scoped to the test; do not rely on global state.

## Laravel Boost rules (from `ui/AGENTS.md`)

- Always use the Boost `search-docs` tool for Laravel/Inertia/Pest/Tailwind/Fortify.
- Use `list-artisan-commands` before running Artisan commands if unsure.
- Activate skills when applicable: `wayfinder-development`, `pest-testing`, `inertia-vue-development`, `tailwindcss-development`, `developing-with-fortify`.
- Use Wayfinder routes from `@/actions` or `@/routes` in the UI.
- Run `vendor/bin/pint --dirty` before finalizing PHP changes.
- Use `php artisan make:` commands for new Laravel files; pass `--no-interaction`.
- For deferred props in Inertia v2, add a skeleton/empty state.

## Laravel testing conventions

- This app uses Pest; create tests with `php artisan make:test --pest`.
- Prefer feature tests unless a unit test is truly appropriate.
- Use model factories in tests; check for existing factory states first.
- Keep test setup minimal and focused on the behavior under test.

## UI structure hints

- Inertia pages live in `ui/resources/js/Pages` unless Vite config says otherwise.
- Use `Inertia::render()` for server routes instead of Blade views.
- Prefer named routes and the `route()` helper for links.
- When debugging Vite manifest errors, run `npm run build` or `composer run dev`.
