# Task Spec: flow-editor-split

## Metadata
- Task ID: flow-editor-split
- Frozen: 2026-05-08
- Repo root: /home/noartem/Projects/kawa
- Primary implementation area: `/home/noartem/Projects/kawa/ui`

## Guidance sources
- `/home/noartem/Projects/kawa/AGENTS.md`
- `/home/noartem/Projects/kawa/ui/AGENTS.md`
- `/home/noartem/Projects/kawa/CLAUDE.md`

## Relevant current-state observations
- `ui/routes/web.php` has `flows.show` pointing to `FlowController@show`; there is no `flows.editor` route yet.
- `ui/app/Http/Controllers/FlowController.php` currently renders `Inertia::render('flows/Editor', ...)` from `show()` and redirects `update()` / `updateStorage()` back to `flows.show` with `deployment` and `tab` query params.
- `ui/app/Http/Controllers/FlowActionController.php` currently redirects every flow action back to `flows.show` with the same `deployment` and `tab` query params.
- `ui/resources/js/pages/flows/Editor.vue` currently combines the whole screen: header, summary, workspace, deployments, past chats, and settings; it also owns deployment/tab URL sync, polling, storage save, chat, and save-before-run/restart behavior.
- `ui/resources/js/components/flows/editor/FlowEditorWorkspace.vue` currently exposes Start/Restart/Stop controls but no explicit Save button in the workspace toolbar.
- `ui/resources/js/pages/flows/ChatDebug.vue` currently uses `flows.show` for both the flow breadcrumb and the explicit “Back to editor” action.
- Existing feature tests assert that `flows.show` renders `flows/Editor` and that editor query-state and redirect assertions are anchored on `flows.show`.

## Original task statement
> Freeze the task into /home/noartem/Projects/kawa/.agent/tasks/flow-editor-split/spec.md. Use the current repository guidance plus the user-approved requirements already established in this conversation. The task is: split the current Flow editor screen into a show page and a dedicated editor page. Requirements: keep flows.show as the canonical detail/show route used by dashboard/sidebar/index/breadcrumbs; add flows.editor and FlowController@editor; Show.vue keeps header, summary, deployments, past chats, settings, removes workspace, and adds a large obvious CTA at the top to open the editor while preserving deployment/tab query state; Editor.vue becomes workspace-only and retains current editor behaviors (URL sync for deployment/tab, graph/logs, chat, storage, polling, save-before-run/restart). Add an explicit Save button in the workspace toolbar immediately left of Start/Restart/Stop. If there are unsaved changes, Start/Restart labels must become Save & Start / Save & Restart. Redirects must become origin-aware via an explicit frontend origin flag: editor-origin update/action requests redirect to flows.editor with preserved deployment/tab; show-origin update/action requests redirect to flows.show. ChatDebug back-to-editor action must point to the new editor route while breadcrumbs may remain to show. Update tests so flows.show renders flows/Show, flows.editor renders flows/Editor, editor-state query assertions move to the editor route, and redirect assertions become origin-aware. Constraints: minimal diff, preserve existing navigation and back-links, preserve selected tab and deployment state, do not break existing flows.show entry points, use existing code conventions, add tests and run targeted tests, run Pint for PHP changes, regenerate Wayfinder routes after route changes. Do not edit production code; only produce the frozen spec with ACs, constraints, non-goals, and a verification plan.

## Acceptance criteria
- AC1: Add a new authenticated `flows.editor` route and matching `FlowController@editor` action. `flows.show` remains the canonical detail route for existing entry points, and `FlowController@show` must render `flows/Show` while `FlowController@editor` must render `flows/Editor`.
- AC2: Create `Show.vue` from the current non-workspace portions of the editor screen. It must keep the existing header, summary, deployments, past chats, and settings sections, remove the workspace section entirely, and add a large obvious CTA near the top that opens `flows.editor` while preserving the current `deployment` and `tab` query state.
- AC3: Refocus `Editor.vue` into a workspace-only page. It must retain the current editor behaviors already implemented on the existing screen: deployment/tab URL sync, deployment switching, graph/log rendering, chat, storage drafts/save flow, polling/refresh behavior, and save-before-run/restart behavior.
- AC4: The workspace toolbar must include an explicit Save button immediately to the left of the Start/Restart/Stop controls. When flow-level changes are unsaved, the primary action labels must change from `Start` / `Restart` to `Save & Start` / `Save & Restart`; when there are no unsaved flow-level changes, the existing `Start` / `Restart` labels remain.
- AC5: Flow update and flow action redirects must become origin-aware through an explicit frontend origin flag. `editor-origin` requests for flow update/storage update/run/stop/restart/deploy/undeploy/archive/restore must redirect to `flows.editor`; `show-origin` requests for those same operations must redirect to `flows.show`; both targets must preserve `flow`, `deployment`, and `tab` state.
- AC6: `ChatDebug` must change its explicit back-to-editor action to the new editor route. The flow breadcrumb may continue to point to `flows.show`.
- AC7: Existing `flows.show` entry points and back-links must keep working without being repointed to the editor route unless the requirement above explicitly calls for that change. Preserving the selected deployment and tab state across show↔editor navigation and across origin-aware redirects is required.
- AC8: Automated test coverage must be updated so that `flows.show` assertions target `flows/Show`, `flows.editor` assertions target `flows/Editor`, editor query-state assertions move to the editor route, and redirect assertions cover both editor-origin and show-origin behavior with preserved `deployment` and `tab` values. Regenerated Wayfinder output must be included after route changes.

## Constraints
- Keep the implementation diff minimal and reuse existing components/patterns where possible.
- Preserve existing navigation, back-links, and current `flows.show` entry points used by dashboard, sidebar, index, and breadcrumbs.
- Preserve selected `deployment` and `tab` state in links, redirects, and editor URL syncing.
- Follow existing Laravel, Inertia, Vue, Pest, and project naming/style conventions.
- Do not add dependencies or create new top-level directories.
- Add/update tests and run only the targeted checks needed for this change.
- Run Pint for any PHP edits.
- Regenerate Wayfinder routes after route changes.

## Assumptions
- If the explicit frontend origin flag is missing or invalid, redirects should fall back to `flows.show` while still preserving resolved `deployment` and `tab` values, because `flows.show` remains the canonical detail route and this is the narrowest backward-compatible default.
- The query parameter names remain `deployment` and `tab`; the split does not require moving those values into path segments or renaming them.
- `Show.vue` may still receive whatever lightweight state is needed to build the CTA and preserve query state, but it does not need to render the workspace or workspace-only tabs.
- The new Save button should follow existing permission/processing rules for saving rather than introducing a new authorization model.

## Non-goals
- Do not redesign the flow detail/editor UI beyond the required split, CTA, and toolbar button/label changes.
- Do not change flow runtime, deployment, chat, storage, or polling business logic beyond what is necessary to preserve current behavior after the route/page split.
- Do not repoint unrelated navigation to `flows.editor` when the requirement says `flows.show` remains canonical.
- Do not introduce new test tooling or dependency changes solely for this task.

## Verification plan
- Regenerate routes for frontend helpers after the route change: `php artisan wayfinder:generate --no-interaction`.
- Run PHP formatting if any PHP files change: `vendor/bin/pint --dirty --format agent`.
- Run the targeted Pest coverage expected to hold the route split and redirect behavior, updating/adding tests in these files or equivalent focused files:
  - `php artisan test --compact tests/Feature/FlowDeploymentsPayloadTest.php`
  - `php artisan test --compact tests/Feature/FlowGraphDefaultsTest.php`
  - `php artisan test --compact tests/Feature/FlowRunErrorHandlingTest.php`
  - `php artisan test --compact tests/Feature/FlowChatTest.php`
- Run `npm run build` if needed to catch Vue/TypeScript/Wayfinder route-import issues introduced by the page split.
