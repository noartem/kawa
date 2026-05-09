import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { summarizeGraphForDebug } from './graphVisibilityDebug.ts';

describe('graphVisibilityDebug', () => {
    it('summarizes graph nodes and edges for console logging', () => {
        const summary = summarizeGraphForDebug({
            nodes: [
                { id: 'event.alpha' },
                { id: 'actor.beta' },
                { name: 'actor.gamma' },
            ],
            edges: [
                { from: 'event.alpha', to: 'actor.beta' },
                { from: 'event.alpha', to: 'actor.gamma' },
            ],
        });

        assert.deepEqual(summary, {
            hasGraph: true,
            nodeCount: 3,
            edgeCount: 2,
            nodeIds: ['event.alpha', 'actor.beta', 'actor.gamma'],
            edgeIds: ['event.alpha->actor.beta', 'event.alpha->actor.gamma'],
        });
    });
});
