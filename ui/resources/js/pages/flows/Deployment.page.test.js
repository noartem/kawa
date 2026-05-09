import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

const source = readFileSync(new URL('./Deployment.vue', import.meta.url), 'utf8');

describe('Deployment page side panels', () => {
    it('tracks graph and log visibility with editor toggle labels', () => {
        assert.match(source, /const graphPanelVisible = ref\(true\);/);
        assert.match(source, /const logsPanelVisible = ref\(true\);/);
        assert.match(source, /flows\.editor\.actions\.close_graph/);
        assert.match(source, /flows\.editor\.actions\.open_graph/);
        assert.match(source, /flows\.editor\.actions\.close_logs/);
        assert.match(source, /flows\.editor\.actions\.open_logs/);
    });

    it('renders graph and logs through the stacked side panels layout', () => {
        assert.match(source, /<StackedSidePanelsLayout/);
        assert.match(source, /:top-active="graphPanelVisible"/);
        assert.match(source, /:bottom-active="logsPanelVisible"/);
        assert.match(source, /<template #top>/);
        assert.match(source, /<template #bottom>/);
        assert.match(source, /<FlowGraph/);
        assert.match(source, /<FlowLogsPanel/);
    });

    it('keeps cross-panel graph and discovery interactions wired together', () => {
        assert.match(source, /focusDiscoveryNode\(payload\);/);
        assert.match(source, /@node-select="openDiscoveryNode"/);
        assert.match(source, /@dispatch-edge-highlight="highlightDispatchPath"/);
        assert.match(source, /@log-edge-focus="focusEdgeHighlight"/);
        assert.match(source, /@select-node="handleLogNodeSelection"/);
    });

    it('syncs hidden graph nodes through the URL and shared panel state', () => {
        assert.match(source, /const hiddenNodeIds = ref<string\[\]>\(\[\]\);/);
        assert.match(source, /setHiddenGraphNodeQueryParams\(query, nextHiddenNodeIds\);/);
        assert.match(source, /const nextHiddenNodeIds = readHiddenNodeIdsFromLocation\(\);/);
        assert.match(source, /hiddenNodeIds\.value = nextHiddenNodeIds;/);
        assert.match(source, /window\.addEventListener\('popstate', handlePopstate\);/);
        assert.match(source, /:hidden-node-ids="hiddenNodeIds"/);
        assert.match(source, /@toggle-node-visibility="toggleHiddenNodeVisibility"/);
    });

    it('logs hidden-node URL and popstate transitions', () => {
        assert.match(source, /logFlowGraphVisibility\('Deployment\.buildDeploymentUrl'/);
        assert.match(source, /logFlowGraphVisibility\('Deployment\.syncBrowserUrl'/);
        assert.match(source, /logFlowGraphVisibility\('Deployment\.toggleHiddenNodeVisibility'/);
        assert.match(source, /logFlowGraphVisibility\('Deployment\.handlePopstate'/);
        assert.match(source, /logFlowGraphVisibility\('Deployment\.onMounted'/);
    });
});
