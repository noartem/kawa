import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

const source = readFileSync(
    new URL('./FlowDiscoveryPanel.vue', import.meta.url),
    'utf8',
);

describe('FlowDiscoveryPanel visibility toggle UI', () => {
    it('reveals the eye button only on row hover or focus', () => {
        assert.match(
            source,
            /group-hover:opacity-100[\s\S]*group-focus-within:opacity-100/,
        );
        assert.match(source, /opacity-0 transition-opacity duration-150/);
    });

    it('animates row opacity changes for hidden nodes', () => {
        assert.match(
            source,
            /transition-\[background-color,opacity\] duration-150/,
        );
    });

    it('logs overview visibility toggle events', () => {
        assert.match(
            source,
            /logFlowGraphVisibility\('FlowDiscoveryPanel\.toggleNodeVisibility'/,
        );
    });
});
