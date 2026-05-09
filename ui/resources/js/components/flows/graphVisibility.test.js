import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    filterFlowGraphByHiddenNodeIds,
    parseHiddenGraphNodeIds,
    setHiddenGraphNodeQueryParams,
} from './graphVisibility.ts';

describe('graphVisibility', () => {
    it('removes hidden nodes and connected edges from the graph payload', () => {
        const graph = {
            nodes: [
                { id: 'event.alpha', type: 'event' },
                { id: 'actor.beta', type: 'actor' },
                { id: 'actor.gamma', type: 'actor' },
            ],
            edges: [
                { from: 'event.alpha', to: 'actor.beta' },
                { from: 'event.alpha', to: 'actor.gamma' },
            ],
            actors: [{ id: 'actor.beta' }, { id: 'actor.gamma' }],
        };

        const filteredGraph = filterFlowGraphByHiddenNodeIds(graph, [
            'actor.beta',
        ]);

        assert.deepEqual(filteredGraph, {
            ...graph,
            nodes: [
                { id: 'event.alpha', type: 'event' },
                { id: 'actor.gamma', type: 'actor' },
            ],
            edges: [{ from: 'event.alpha', to: 'actor.gamma' }],
        });
    });

    it('parses repeated hidden params into a normalized unique list', () => {
        const query = new URLSearchParams(
            'hidden= actor.beta &hidden=event.alpha&hidden=actor.beta',
        );

        assert.deepEqual(parseHiddenGraphNodeIds(query), [
            'actor.beta',
            'event.alpha',
        ]);
    });

    it('writes hidden params without disturbing unrelated query state', () => {
        const query = new URLSearchParams(
            'deployment=production&hidden=actor.beta&logs=0',
        );

        setHiddenGraphNodeQueryParams(query, ['event.alpha', 'actor.beta']);

        assert.equal(
            query.toString(),
            'deployment=production&logs=0&hidden=actor.beta&hidden=event.alpha',
        );
    });
});
