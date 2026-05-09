import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    FLOW_GRAPH_EDGE_BASE_SIZE,
    FLOW_GRAPH_EDGE_HIGHLIGHT_SIZE_DELTA,
    FLOW_GRAPH_EDGE_HOVER_SIZE,
    FLOW_GRAPH_FALLBACK_ARROW_MARKER,
} from './graphStyle.ts';

describe('flow graph style config', () => {
    it('keeps default edge weights lighter than highlight states', () => {
        assert.equal(FLOW_GRAPH_EDGE_BASE_SIZE, 1.3);
        assert.equal(FLOW_GRAPH_EDGE_HOVER_SIZE, 2.4);
        assert.equal(FLOW_GRAPH_EDGE_HIGHLIGHT_SIZE_DELTA, 2.1);
        assert.ok(FLOW_GRAPH_EDGE_HOVER_SIZE > FLOW_GRAPH_EDGE_BASE_SIZE);
    });

    it('uses a smaller fixed-size fallback arrow marker', () => {
        assert.deepEqual(FLOW_GRAPH_FALLBACK_ARROW_MARKER, {
            width: 6,
            height: 6,
            refX: 5.4,
            refY: 3,
            path: 'M 0 0 L 6 3 L 0 6 z',
            units: 'userSpaceOnUse',
        });
    });
});
