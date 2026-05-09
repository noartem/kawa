import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

const source = readFileSync(new URL('./Editor.vue', import.meta.url), 'utf8');

describe('Editor page hidden graph nodes', () => {
    it('persists hidden nodes in the editor URL state', () => {
        assert.match(source, /const hiddenNodeIds = ref<string\[\]>\(\[\]\);/);
        assert.match(source, /setHiddenGraphNodeQueryParams\(query, nextHiddenNodeIds\);/);
        assert.match(source, /hiddenNodeIds: parseHiddenGraphNodeIds\(query\),/);
        assert.match(source, /hiddenNodeIds\.value = nextState\.hiddenNodeIds;/);
    });

    it('wires hidden-node toggles into discovery and graph panels', () => {
        assert.match(source, /<FlowDiscoveryPanel[\s\S]*:hidden-node-ids="hiddenNodeIds"/);
        assert.match(source, /<FlowGraph[\s\S]*:hidden-node-ids="hiddenNodeIds"/);
        assert.match(source, /@toggle-node-visibility="toggleHiddenNodeVisibility"/);
    });

    it('logs hidden-node URL and state transitions', () => {
        assert.match(source, /logFlowGraphVisibility\('Editor\.appendEditorQuery'/);
        assert.match(source, /logFlowGraphVisibility\('Editor\.syncBrowserUrl'/);
        assert.match(source, /logFlowGraphVisibility\('Editor\.toggleHiddenNodeVisibility'/);
        assert.match(source, /logFlowGraphVisibility\('Editor\.handlePopstate'/);
        assert.match(source, /logFlowGraphVisibility\('Editor\.onMounted'/);
    });

    it('disables the top progress bar during polling refreshes', () => {
        assert.match(
            source,
            /router\.visit\(buildEditorUrl\(\), \{[\s\S]*showProgress: false,[\s\S]*only: \[\.\.\.refreshOnlyProps\]/,
        );
    });
});
