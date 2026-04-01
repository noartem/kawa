import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    flushPendingDispatchPathHighlight,
    propagateDispatchPathHighlight,
    resolveDispatchHighlightEdgeIds,
    resolveEdgeHighlightAttributes,
} from './graphHighlights.ts';

describe('graph highlight helpers', () => {
    it('resolves trigger, dispatch, and downstream edges for a dispatch path', () => {
        const graph = {
            nodes: [
                { id: 'Start', type: 'event' },
                { id: 'Worker', type: 'actor' },
                { id: 'Done', type: 'event' },
                { id: 'Archive', type: 'actor' },
            ],
            edges: [
                { from: 'Start', to: 'Worker' },
                { from: 'Worker', to: 'Done' },
                { from: 'Done', to: 'Archive' },
                { from: 'Missing', to: 'Done' },
            ],
        };

        const edgeIds = resolveDispatchHighlightEdgeIds(graph, {
            actor: 'Worker',
            event: 'Done',
            triggerEvent: 'Start',
        });

        assert.deepEqual([...edgeIds].sort(), [
            'Done->Archive',
            'Start->Worker',
            'Worker->Done',
        ]);
    });

    it('propagates dispatch highlights to inline and fullscreen renderers', () => {
        const calls = [];
        const payload = {
            actor: 'Worker',
            event: 'Done',
            triggerEvent: 'Start',
        };

        propagateDispatchPathHighlight(
            [
                {
                    highlightDispatchPath(nextPayload) {
                        calls.push(['inline', nextPayload]);
                    },
                },
                null,
                {
                    highlightDispatchPath(nextPayload) {
                        calls.push(['fullscreen', nextPayload]);
                    },
                },
            ],
            payload,
        );

        assert.deepEqual(calls, [
            ['inline', payload],
            ['fullscreen', payload],
        ]);
    });

    it('replays the latest pending dispatch highlight once a renderer mounts', () => {
        const calls = [];
        const payload = {
            actor: 'Worker',
            event: 'Done',
            triggerEvent: 'Start',
        };

        const pendingWithoutRenderer = flushPendingDispatchPathHighlight(
            null,
            payload,
        );

        assert.deepEqual(pendingWithoutRenderer, payload);

        const pendingAfterMount = flushPendingDispatchPathHighlight(
            {
                highlightDispatchPath(nextPayload) {
                    calls.push(nextPayload);
                },
            },
            pendingWithoutRenderer,
        );

        assert.equal(pendingAfterMount, null);
        assert.deepEqual(calls, [payload]);
    });

    it('keeps programmatic highlights visible while hover highlighting is active', () => {
        const highlightedEdge = resolveEdgeHighlightAttributes({
            edgeId: 'Worker->Done',
            baseColor: '#34d399',
            baseSize: 2,
            hoveredNodeId: 'AnotherNode',
            hoverHighlightedEdgeIds: new Set(['Other->Edge']),
            programmaticHighlightStrength: 1,
        });

        assert.equal(highlightedEdge.zIndex, 2);
        assert.equal(highlightedEdge.size, 4.4);
        assert.notEqual(highlightedEdge.color, '#34d399');

        const dimmedEdge = resolveEdgeHighlightAttributes({
            edgeId: 'Worker->Done',
            baseColor: '#34d399',
            baseSize: 2,
            hoveredNodeId: 'AnotherNode',
            hoverHighlightedEdgeIds: new Set(['Other->Edge']),
            programmaticHighlightStrength: 0,
        });

        assert.deepEqual(dimmedEdge, {
            color: 'rgba(148, 163, 184, 0.12)',
            zIndex: 0,
        });
    });
});
