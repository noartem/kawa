# Evidence Bundle: flow-editor-dev-prod-modes

## Summary
- Overall status: PASS
- Last updated: 2026-04-09T11:02:56+00:00

## Acceptance criteria evidence

### AC1
- Status: PASS
- Proof:
  - `ui/app/Http/Controllers/FlowController.php:133-147` exposes normalized `activeDeploymentType` and `activeEditorTab` props from the request and includes both `lastProductionDeployment` and `lastDevelopmentDeployment` in the page payload.
  - `ui/app/Http/Controllers/FlowController.php:681-706` normalizes the `deployment` and `tab` query params with defaults of `development` and `overview`.
  - `ui/resources/js/pages/flows/Editor.vue:470-556` owns URL-backed deployment/tab state via `appendEditorQuery()`, `buildEditorUrl()`, `syncBrowserUrl()`, `readEditorStateFromLocation()`, `setActiveDeployment()`, and `setActiveEditorTab()`.
  - `ui/resources/js/components/flows/editor/FlowEditorHeader.vue:7-17,36-66` renders the Dev/Prod segmented switch and emits `update:deploymentType`.
  - `ui/tests/Feature/FlowDeploymentsPayloadTest.php:98-174` proves the default payload is `development` + `overview` and that `deployment=production&tab=chat` is restored from the URL.
- Gaps:
  - None.

### AC2
- Status: PASS
- Proof:
  - `ui/resources/js/pages/flows/Editor.vue:182-200,383-393` keeps shared editor/chat/history state on the page while selecting deployment-scoped graph/log/discovery data from `selectedDeployment`.
  - `ui/resources/js/pages/flows/Editor.vue:1330-1354` routes the primary start/stop control to `flowRun`/`flowStop` for development and `flowDeploy`/`flowUndeploy` for production.
  - `ui/resources/js/pages/flows/Editor.vue:1480-1547` shows overview metrics from the selected deployment and passes deployment-specific graph, logs, discovery webhooks, storage environment, and active status into `FlowEditorWorkspace`.
  - `ui/resources/js/components/flows/editor/FlowEditorWorkspace.vue:80-114,147-168,649-654` consumes deployment-generic props, uses `currentDeploymentActive` for run-state UI, and switches log empty-state copy between dev and prod.
  - `ui/resources/js/components/flows/editor/FlowEditorSummary.vue:6-22,66-119` summarizes whichever deployment is active instead of a production-only view.
  - `ui/tests/Feature/FlowDeploymentsPayloadTest.php:101-145` proves the payload contains separate production and development deployment data, including logs and graph snapshots.
- Gaps:
  - None.

### AC3
- Status: PASS
- Proof:
  - `ui/resources/js/components/flows/editor/types.ts:12-21` defines `FlowEditorTab` as `overview` plus the workspace tabs.
  - `ui/resources/js/i18n/messages.ts:442,1147` adds the overview tab label in both locales.
  - `ui/resources/js/pages/flows/Editor.vue:511-556,838-843,1433-1477` restores deployment/tab state from the URL on first load, updates browser history during tab changes, and handles `popstate` so back/forward restores editor state.
  - `ui/tests/Feature/FlowDeploymentsPayloadTest.php:147-174` proves the `tab` query param is accepted and returned in the Inertia payload.
- Gaps:
  - None.

### AC4
- Status: PASS
- Proof:
  - `ui/app/Http/Controllers/FlowController.php:242-254,263-264,681-706` preserves normalized `deployment` and `tab` when redirecting after save and storage updates.
  - `ui/app/Http/Controllers/FlowActionController.php:30-142` preserves normalized editor query state for run, stop, deploy, undeploy, archive, and restore redirects.
  - `ui/resources/js/pages/flows/Editor.vue:1261-1376` appends the current editor query state to save, storage update, run, stop, archive, and restore requests.
  - `ui/tests/Feature/FlowRunErrorHandlingTest.php:41-58,544-565,592-615` proves run and storage-update redirects keep the current query state.
  - `ui/tests/Feature/FlowGraphDefaultsTest.php:171-193` proves a successful update redirect preserves `deployment=production&tab=editor`.
- Gaps:
  - None.

### AC5
- Status: PASS
- Proof:
  - `ui/tests/Feature/FlowDeploymentsPayloadTest.php`, `ui/tests/Feature/FlowRunErrorHandlingTest.php`, and `ui/tests/Feature/FlowGraphDefaultsTest.php` cover payload shape and redirect/query preservation.
  - `ui/resources/js/pages/flows/Editor.vue`, `ui/resources/js/components/flows/editor/FlowEditorHeader.vue`, `ui/resources/js/components/flows/editor/FlowEditorSummary.vue`, `ui/resources/js/components/flows/editor/FlowEditorWorkspace.vue`, `ui/resources/js/components/flows/editor/types.ts`, `ui/resources/js/i18n/messages.ts`, `ui/app/Http/Controllers/FlowController.php`, and `ui/app/Http/Controllers/FlowActionController.php` contain the implementation under test.
  - Fresh checks passed and were captured under `.agent/tasks/flow-editor-dev-prod-modes/raw/`.
- Gaps:
  - None.

## Commands run
- `vendor/bin/pint --dirty --format agent` in `ui/` -> exit 0. Raw: `.agent/tasks/flow-editor-dev-prod-modes/raw/lint.txt`
- `npm run lint` in `ui/` -> exit 0. Raw: `.agent/tasks/flow-editor-dev-prod-modes/raw/lint.txt`
- `npm run build` in `ui/` -> exit 0. Raw: `.agent/tasks/flow-editor-dev-prod-modes/raw/build.txt`
- `php artisan test --compact tests/Feature/FlowDeploymentsPayloadTest.php` in `ui/` -> exit 0, `11 passed (275 assertions)`. Raw: `.agent/tasks/flow-editor-dev-prod-modes/raw/flow-deployments-payload-test.txt`
- `php artisan test --compact tests/Feature/FlowRunErrorHandlingTest.php` in `ui/` -> exit 0, `27 passed (132 assertions)`. Raw: `.agent/tasks/flow-editor-dev-prod-modes/raw/flow-run-error-handling-test.txt`
- `php artisan test --compact tests/Feature/FlowGraphDefaultsTest.php` in `ui/` -> exit 0, `7 passed (52 assertions)`. Raw: `.agent/tasks/flow-editor-dev-prod-modes/raw/flow-graph-defaults-test.txt`

## Raw artifacts
- `.agent/tasks/flow-editor-dev-prod-modes/raw/build.txt`
- `.agent/tasks/flow-editor-dev-prod-modes/raw/lint.txt`
- `.agent/tasks/flow-editor-dev-prod-modes/raw/flow-deployments-payload-test.txt`
- `.agent/tasks/flow-editor-dev-prod-modes/raw/flow-run-error-handling-test.txt`
- `.agent/tasks/flow-editor-dev-prod-modes/raw/flow-graph-defaults-test.txt`

## Known gaps
- None.
