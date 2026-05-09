import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

const source = readFileSync(new URL('./FlowGraph.vue', import.meta.url), 'utf8');

describe('FlowGraph hidden node visibility', () => {
    it('passes the filtered graph directly to the live renderers', () => {
        assert.match(source, /const filteredGraph = computed<Record<string, unknown> \| null>\(\(\) => \{/);
        assert.match(source, /<FlowGraphRenderer[\s\S]*:graph="filteredGraph"/);
    });

    it('keeps renderer instances mounted for in-place graph animation', () => {
        assert.doesNotMatch(source, /hiddenNodeVisibilityKey/);
        assert.doesNotMatch(source, /flow-graph-visibility/);
        assert.doesNotMatch(source, /<Transition name=/);
    });

    it('logs filtered graph changes and forwarded toggle events', () => {
        assert.match(source, /logFlowGraphVisibility\('FlowGraph\.filteredGraph'/);
        assert.match(source, /logFlowGraphVisibility\('FlowGraph\.toggleNodeVisibility'/);
    });
});
