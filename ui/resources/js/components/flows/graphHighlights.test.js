import assert from 'node:assert/strict';
import test from 'node:test';

import {
    getProgrammaticHighlightStrength,
    PROGRAMMATIC_HIGHLIGHT_FADE_MS,
    PROGRAMMATIC_HIGHLIGHT_FLASH_MS,
    pruneProgrammaticHighlights,
    resolveDirectHighlightEdgeIds,
    resolveEdgeHighlightAttributes,
    resolveHighlightedEdgeSize,
    resolveNodeHighlightAttributes,
} from './graphHighlights.ts';
import {
    FLOW_GRAPH_EDGE_HIGHLIGHT_SIZE_DELTA,
    FLOW_GRAPH_EDGE_HOVER_SIZE,
} from './graphStyle.ts';

test('getProgrammaticHighlightStrength stays full during flash window', () => {
    const highlights = new Map([['node-a', 100]]);

    assert.equal(
        getProgrammaticHighlightStrength(
            highlights,
            'node-a',
            100 + PROGRAMMATIC_HIGHLIGHT_FLASH_MS,
        ),
        1,
    );
});

test('getProgrammaticHighlightStrength fades after flash window', () => {
    const highlights = new Map([['node-a', 100]]);
    const midway =
        100 +
        PROGRAMMATIC_HIGHLIGHT_FLASH_MS +
        PROGRAMMATIC_HIGHLIGHT_FADE_MS / 2;

    assert.equal(
        getProgrammaticHighlightStrength(highlights, 'node-a', midway),
        0.5,
    );
});

test('pruneProgrammaticHighlights removes expired entries', () => {
    const highlights = new Map([
        ['fresh', 100],
        ['expired', 10],
    ]);

    const hasActiveHighlights = pruneProgrammaticHighlights(
        highlights,
        10 + PROGRAMMATIC_HIGHLIGHT_FLASH_MS + PROGRAMMATIC_HIGHLIGHT_FADE_MS,
    );

    assert.equal(hasActiveHighlights, true);
    assert.deepEqual([...highlights.keys()], ['fresh']);
});

test('resolveNodeHighlightAttributes emphasizes focused nodes', () => {
    const attributes = resolveNodeHighlightAttributes({
        baseColor: '#34d399',
        baseSize: 10,
        hovered: false,
        programmaticHighlightStrength: 1,
    });

    assert.equal(attributes.size, 14);
    assert.equal(attributes.zIndex, 4);
    assert.equal(attributes.forceLabel, true);
    assert.equal(attributes.color, 'rgb(34, 197, 94)');
});

test('resolveNodeHighlightAttributes preserves hover sizing without focus', () => {
    const attributes = resolveNodeHighlightAttributes({
        baseColor: '#38bdf8',
        baseSize: 10,
        hovered: true,
        programmaticHighlightStrength: 0,
    });

    assert.equal(attributes.size, 11.25);
    assert.equal(attributes.zIndex, 2);
    assert.equal('forceLabel' in attributes, false);
    assert.equal('color' in attributes, false);
});

test('resolveDirectHighlightEdgeIds returns a single matching edge', () => {
    const edgeIds = resolveDirectHighlightEdgeIds(
        {
            nodes: [
                { id: 'Start', type: 'event' },
                { id: 'Worker', type: 'actor' },
            ],
            edges: [{ from: 'Start', to: 'Worker' }],
        },
        {
            from: 'Start',
            to: 'Worker',
        },
    );

    assert.deepEqual([...edgeIds], ['Start->Worker']);
});

test('resolveEdgeHighlightAttributes dims unrelated edges during hover', () => {
    assert.deepEqual(
        resolveEdgeHighlightAttributes({
            edgeId: 'Worker->Done',
            baseColor: '#34d399',
            baseSize: 2,
            hoverActive: true,
            hoverHighlightedEdgeIds: new Set(['Other->Edge']),
            programmaticHighlightStrength: 0,
        }),
        {
            color: 'rgba(148, 163, 184, 0.12)',
            zIndex: 0,
        },
    );
});

test('resolveEdgeHighlightAttributes preserves stronger programmatic edge highlight', () => {
    const attributes = resolveEdgeHighlightAttributes({
        edgeId: 'Worker->Done',
        baseColor: '#34d399',
        baseSize: 2,
        hoverActive: true,
        hoverHighlightedEdgeIds: new Set(['Worker->Done']),
        programmaticHighlightStrength: 1,
    });

    assert.equal(
        attributes.size,
        2 + FLOW_GRAPH_EDGE_HIGHLIGHT_SIZE_DELTA,
    );
    assert.equal(attributes.zIndex, 3);
    assert.notEqual(attributes.color, '#34d399');
});

test('resolveHighlightedEdgeSize respects hover and programmatic width', () => {
    assert.equal(
        resolveHighlightedEdgeSize({
            baseSize: 2,
            hoverHighlighted: true,
            programmaticHighlightStrength: 1,
        }),
        2 + FLOW_GRAPH_EDGE_HIGHLIGHT_SIZE_DELTA,
    );

    assert.equal(
        resolveHighlightedEdgeSize({
            baseSize: 1,
            hoverHighlighted: true,
            programmaticHighlightStrength: 0,
        }),
        FLOW_GRAPH_EDGE_HOVER_SIZE,
    );
});
