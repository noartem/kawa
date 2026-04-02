# TODO

## 1. Webhook discovery implementation target
- [x] Trace webhook implementation line selection in `ui/resources/js/components/flows/editor/FlowDiscoveryPanel.vue`.
- [x] Make `implementation` point to the first actual `Webhook.by(...)` usage/call site instead of an imported event line.
- [x] Preserve the current fallback path when graph data is unavailable.
- [x] Add or update backend tests covering webhook source-line selection.

## 2. Webhook page navigation and dedicated deployment page
- [x] Make `flow #N "name"` on `ui/resources/js/pages/webhooks/Show.vue` link to the flow page.
- [x] Make `run #N` on `ui/resources/js/pages/webhooks/Show.vue` link to a dedicated deployment page.
- [x] Add a new route/page for a single deployment, shaped like `/flows/{flow}/deployments/{deployment}`.
- [x] Show top navigation/breadcrumbs from the deployment page back to flows index, the flow page, and all deployments.
- [x] Reuse existing deployment details UI instead of duplicating it.
- [x] Add backend/frontend tests for route access and flow-deployment ownership.

## 3. Fullscreen graph modal details
- [x] Extend the fullscreen graph modal to show Discovery-like actor/event details inside the modal.
- [x] Reuse the existing interaction model from `ui/resources/js/components/flows/editor/FlowDeploymentDetailsDialog.vue` where possible.
- [x] Keep the modal open while browsing details.
- [x] Close the modal and jump to code when `implementation` is clicked.

## 4. Event dispatch graph highlighting
- [x] Add a programmatic edge-highlight mechanism to `ui/resources/js/components/flows/FlowGraphRenderer.vue`.
- [x] Highlight dispatch-related path edges in green.
- [x] Animate the highlight as flash-then-fade without breaking hover highlighting.
- [x] Verify the behavior in inline and fullscreen graph views.

## 5. Fresh log highlighting
- [x] Add transient freshness state for newly arrived logs in `ui/resources/js/components/FlowLogsPanel.vue`.
- [x] Tint new logs slightly green and fade the effect over time.
- [x] Preserve current autoscroll and stable row rendering behavior.

## 6. Chat markdown via unified
- [x] Replace the custom renderer in `ui/resources/js/lib/markdown.ts` with a safe `unified` pipeline.
- [x] Add the required markdown and sanitization dependencies.
- [x] Preserve expected markdown features used in chat messages.
- [x] Add focused tests for safe rendering.

## 7. Changes tab accordion migration
- [x] Replace the manual changes-tab expand/collapse implementation with Reka UI accordion primitives.
- [x] Keep the existing visual styling unchanged.
- [x] Add local accordion wrappers under `ui/resources/js/components/ui` if needed.
- [x] Verify keyboard accessibility and expected open/close behavior.

## 8. Webhook Discovery quick JSON sender
- [x] Add an inline send-JSON control on the webhook event items inside the Discovery tab.
- [x] Let the user enter JSON and dispatch it directly from Discovery without opening the separate webhook page.
- [x] Reuse existing webhook dispatch behavior and validation where possible.
- [x] Keep the interaction lightweight so it fits naturally into the existing Discovery UI.

## 9. Prevent page scroll chaining from editor-side panels
- [x] Prevent outer page scrolling when inner scrollable areas reach their scroll bounds.
- [x] Apply this to the logs container, editor-side changes list, Discovery list, and chat panel.
- [x] Keep those panels feeling full-height and self-contained inside the editor workspace.
- [x] Preserve intentional scrolling inside the panel itself.

## 10. Доработки (проверить лично)
- [x] Затухание новых логов немного быстрее
- [x] Добавить анимацию на появление логов
- [x] Добавить анимацию для аккардеона изменений
- [x] Ненужные скругления у codemirror в списке изменений
- [x] Если несколько изменений свернуты, то между ними сразу два бордера (1px+1px и выглядит более жирно чем должно быть)
- [x] В модалке графа в панели дисковери список акторов/событий можно отрисоывать без бордера и лишних отступов. Пусть определяется в компоненте, где используется компонент этого списка, а не в самом списке.
- [x] Должны подсвечивать с затуханиям ребра при возникновении событий dispatch
- [x] Сделать quick send json более компактным, убрать лишнее
- [x] В просмотр payload в логах добавить кнопку "скопировать"
- [x] LLM чат не работает после первого сообщения - проблема скорее всего в том как отправляется история, в историю надо включать моменты apply отдельным сообщением
- [x] Штука с превентом скролла сделана точно в Changes, наверно в других очевидных местах тоже, но проблема с редактором кода (Code editor) - там не скролл работает по прежнему и в конце начинает скролить страницу

## 11. E2E
- [x] Запусти все тесты и проверки, сделай полный отчет по состоянию проекта
