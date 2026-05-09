import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

const source = readFileSync(
    new URL('./FlowGraphRenderer.vue', import.meta.url),
    'utf8',
);

describe('FlowGraphRenderer live layout animation', () => {
    it('animates in-place sigma updates instead of remounting the graph', () => {
        assert.match(source, /import \{ animateNodes \} from 'sigma\/utils';/);
        assert.match(source, /const syncSigmaGraphState = \(nextGraphState: BuiltGraphState\): void => \{/);
        assert.match(source, /const scheduleSigmaAnimationRefresh = \(\): void => \{/);
        assert.match(source, /sigmaRenderer\.scheduleRefresh\(\);/);
        assert.match(source, /cancelNodeAnimation = animateNodes\(/);
        assert.match(source, /scheduleSigmaAnimationRefresh\(\);/);
        assert.match(source, /duration: SIGMA_LAYOUT_ANIMATION_DURATION_MS/);
    });

    it('seeds the next layout from current sigma coordinates', () => {
        assert.match(source, /const seed = resolveFiniteGraphPosition\([\s\S]*seedPositions\.get\(node\.id\),/);
        assert.match(source, /const readSigmaNodePositions = \(graph: Graph\): Map<string, GraphNodePosition> => \{/);
        assert.match(source, /readSigmaNodePositions\(sigmaRenderer\.getGraph\(\)\)/);
    });

    it('sanitizes invalid sigma coordinates before reuse and refresh', () => {
        assert.match(source, /const resolveFiniteGraphPosition = \(/);
        assert.match(source, /if \(!isFiniteGraphCoordinate\(x\) \|\| !isFiniteGraphCoordinate\(y\)\) \{/);
        assert.match(source, /sigmaGraph\.mergeNodeAttributes\([\s\S]*resolveFiniteGraphPosition\(/);
    });

    it('preserves sigma positional attributes in reducers', () => {
        assert.match(source, /nodeReducer: \(node, data\) => \{[\s\S]*return \{[\s\S]*\.\.\.data,[\s\S]*\.\.\.resolveNodeHighlightAttributes\(/);
        assert.match(source, /edgeReducer: \(edge, data\) => \{[\s\S]*return \{[\s\S]*\.\.\.data,[\s\S]*\.\.\.resolveEdgeHighlightAttributes\(/);
    });

    it('animates node exit when the filtered graph becomes empty', () => {
        assert.match(source, /const animateSigmaGraphExit = \(\): void => \{/);
        assert.match(source, /animationTargets\[nodeId\] = \{\s*size: 0\s*\};/);
    });

    it('logs renderer graph lifecycle steps for debugging', () => {
        assert.match(source, /logFlowGraphVisibility\('FlowGraphRenderer\.watchGraph\.start'/);
        assert.match(source, /logFlowGraphVisibility\('FlowGraphRenderer\.syncSigmaGraphState'/);
        assert.match(source, /logFlowGraphVisibility\('FlowGraphRenderer\.syncFallbackGraphState'/);
        assert.match(source, /logFlowGraphVisibility\('FlowGraphRenderer\.mountRenderer\.sigma'/);
        assert.match(source, /logFlowGraphVisibility\('FlowGraphRenderer\.animateSigmaGraphExit'/);
    });

    it('updates fallback graphs without remounting through a zero-sized container', () => {
        assert.match(source, /const syncFallbackGraphState = \(nextGraphState: BuiltGraphState\): void => \{/);
        assert.match(source, /if \(fallbackGraph\.value\) \{[\s\S]*FlowGraphRenderer\.watchGraph\.syncFallback/);
        assert.match(source, /syncFallbackGraphState\(buildGraphState\(props\.graph\)\);/);
    });
});
