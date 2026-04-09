# Task Spec: flow-editor-dev-prod-modes

## Metadata
- Task ID: flow-editor-dev-prod-modes
- Created: 2026-04-09T05:58:12+00:00
- Repo root: /home/noartem/Projects/kawa-new-deploy
- Working directory at init: /home/noartem/Projects/kawa-new-deploy

## Guidance sources
- AGENTS.md

## Original task statement
## 0. Новый подход к деплоям

В редакторе Flow добавить переключатель между режимами Dev и Prod. Они оба
одинаковые, но кнопка Start в них запускает разные деплои. Редактор кода, чат,
изменения у них общие, но граф/логи/discovery для каждого деплоя свои.

В URL нужно параметрами указывать тип деплоя и активную вкладку
(редактор/чат/обзор/т.д.) - и использовать это при первой загрузке страницы и
при переходе по ссылкам.

## Acceptance criteria
- AC1: The Flow editor supports a query-driven deployment mode switch between development and production. The selected mode is taken from the URL on first load, can be changed in the editor UI, and is preserved when navigating within the editor page.
- AC2: Code editing, chat, and code-change history remain shared for both modes, while the deployment-scoped surfaces switch with the selected mode. At minimum this includes the primary start/stop action plus the graph, logs, discovery data, and storage environment shown for the selected mode.
- AC3: The editor tracks the active view in the URL and uses it on first load. Supported view values must cover the current editor surfaces users navigate between in this page, including overview plus the existing workspace tabs.
- AC4: Flow page actions that return to `flows.show` preserve the current editor query state so mode/view do not reset after save, run, stop, deploy, undeploy, archive, restore, or storage updates.
- AC5: Automated coverage proves the editor payload and flow action redirects preserve the new query-driven state and expose the deployment data needed for both modes.

## Constraints
- Keep all task artifacts under `.agent/tasks/flow-editor-dev-prod-modes/`.
- Keep the change set minimal and follow existing Laravel + Inertia + Vue patterns already used by the Flow editor.
- Do not change deployment semantics in `FlowService`: development start still uses `start()` and production deployment still uses `deployProduction()` / `undeployProduction()`.
- Do not add dependencies or create new base directories.
- Tests are required for the changed behavior, and targeted project checks must be run from `ui/`.

## Non-goals
- Redesigning the Flow editor layout beyond the mode selector and URL-driven view state.
- Changing deployment list pages, deployment detail pages, webhook delivery behavior, or flow runtime internals beyond what the editor needs to select and display the right deployment.
- Adding new persistence outside the URL for editor mode or active view.

## Verification plan
- Build:
- `npm run build`
- Unit tests:
- `php artisan test --compact tests/Feature/FlowDeploymentsPayloadTest.php`
- `php artisan test --compact tests/Feature/FlowRunErrorHandlingTest.php`
- Integration tests:
- Covered by the targeted Flow feature tests above.
- Lint:
- `vendor/bin/pint --dirty --format agent`
- Manual checks:
- Load `flows.show` with and without query params and confirm the initial deployment mode and editor view follow the URL.
- Change the mode in the editor and verify the start/stop control and deployment-scoped panels switch to the selected deployment type while code/chat/history remain unchanged.
- Trigger a save or deployment action and confirm the browser stays on the same `deployment` and `tab` query state after the Inertia round trip.

## Assumptions
- The query parameter names are `deployment` for the selected mode and `tab` for the active editor view.
- Valid `deployment` values are `development` and `production`; invalid or missing values fall back to `development`.
- Valid `tab` values cover `overview` plus the existing workspace tabs: `editor`, `chat`, `storage`, `discovery`, and `changes`; invalid or missing values fall back to `overview`.
- For production mode, the editor should show the latest production deployment data, not only the currently active production run, so users can inspect production-specific graph/log/discovery state even when production is inactive.
