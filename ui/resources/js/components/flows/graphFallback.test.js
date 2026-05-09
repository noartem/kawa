import test from 'node:test';
import assert from 'node:assert/strict';

import {
    centerSvgViewportOnPoint,
    createDefaultSvgViewport,
    expandSvgBounds,
    estimateSvgLabelFrame,
    interpolateSvgViewport,
    panSvgViewport,
    resolveFallbackEdgeColor,
    resolveFocusedSvgViewportOnPoint,
    resolveSvgLine,
    resolveSvgViewportFromBounds,
    resolveSvgWheelPixelDelta,
    resolveSvgWheelZoomMode,
    resolveSvgViewportZoomPercent,
    resolveSvgWheelZoomScale,
    scaleSvgViewport,
    zoomSvgViewport,
} from './graphFallback.ts';

test('resolveSvgLine trims the line away from node centers', () => {
    const line = resolveSvgLine({ x: 100, y: 100 }, { x: 300, y: 100 }, 20);

    assert.deepEqual(line, {
        x1: 120,
        y1: 100,
        x2: 280,
        y2: 100,
    });
});

test('estimateSvgLabelFrame keeps labels centered above nodes', () => {
    const frame = estimateSvgLabelFrame('NormalizeIntake', { x: 200, y: 240 }, 16);

    assert.equal(frame.width, 123);
    assert.equal(frame.x, 138.5);
    assert.equal(frame.y, 178);
    assert.equal(frame.height, 28);
    assert.equal(frame.textY, 192.5);
});

test('resolveFallbackEdgeColor dims non-highlighted hovered edges', () => {
    assert.equal(
        resolveFallbackEdgeColor('#34d399', 'node-1', false, 0),
        'rgba(148, 163, 184, 0.18)',
    );

    assert.equal(
        resolveFallbackEdgeColor('#34d399', 'node-1', true, 0),
        '#34d399',
    );

    assert.equal(
        resolveFallbackEdgeColor('#34d399', null, false, 0.8),
        '#22c55e',
    );
});

test('zoomSvgViewport preserves the current center point', () => {
    const viewport = createDefaultSvgViewport();
    const zoomed = zoomSvgViewport(viewport, 'in');

    assert.equal(resolveSvgViewportZoomPercent(zoomed), 120);
    assert.equal(zoomed.x + zoomed.width / 2, 500);
    assert.equal(zoomed.y + zoomed.height / 2, 350);
});

test('zoomSvgViewport keeps the pointer anchor stable', () => {
    const viewport = createDefaultSvgViewport();
    const zoomed = zoomSvgViewport(viewport, 'in', {
        anchor: { x: 100, y: 100 },
        baseViewport: viewport,
    });

    assert.equal(resolveSvgViewportZoomPercent(zoomed, viewport), 120);
    assert.equal((100 - zoomed.x) / zoomed.width, 0.1);
    assert.equal((100 - zoomed.y) / zoomed.height, 100 / 700);
});

test('scaleSvgViewport applies continuous zoom around the anchor', () => {
    const viewport = createDefaultSvgViewport();
    const zoomed = scaleSvgViewport(viewport, 1.08, {
        anchor: { x: 250, y: 175 },
        baseViewport: viewport,
    });

    assert.equal(resolveSvgViewportZoomPercent(zoomed, viewport), 108);
    assert.equal((250 - zoomed.x) / zoomed.width, 0.25);
    assert.equal((175 - zoomed.y) / zoomed.height, 0.25);
});

test('panSvgViewport moves the view with the pointer direction', () => {
    const viewport = createDefaultSvgViewport();
    const panned = panSvgViewport(viewport, 120, -40);

    assert.deepEqual(panned, {
        x: -120,
        y: 40,
        width: 1000,
        height: 700,
    });
});

test('centerSvgViewportOnPoint centers the viewport on a point', () => {
    const baseViewport = createDefaultSvgViewport();
    const zoomedViewport = {
        x: 100,
        y: 100,
        width: 500,
        height: 350,
    };

    const centered = centerSvgViewportOnPoint(
        zoomedViewport,
        { x: 400, y: 300 },
        baseViewport,
    );

    assert.deepEqual(centered, {
        x: 150,
        y: 125,
        width: 500,
        height: 350,
    });
});

test('centerSvgViewportOnPoint clamps to the base viewport bounds', () => {
    const baseViewport = createDefaultSvgViewport();
    const zoomedViewport = {
        x: 200,
        y: 175,
        width: 500,
        height: 350,
    };

    const centered = centerSvgViewportOnPoint(
        zoomedViewport,
        { x: 990, y: 690 },
        baseViewport,
    );

    assert.deepEqual(centered, {
        x: 500,
        y: 350,
        width: 500,
        height: 350,
    });
});

test('resolveFocusedSvgViewportOnPoint applies target zoom and centering', () => {
    const viewport = resolveFocusedSvgViewportOnPoint(
        { x: 400, y: 300 },
        170,
        createDefaultSvgViewport(),
    );

    assert.equal(resolveSvgViewportZoomPercent(viewport), 170);
    assert.equal(Math.round(viewport.x), 106);
    assert.equal(Math.round(viewport.y), 94);
});

test('interpolateSvgViewport blends between two viewport states', () => {
    const interpolated = interpolateSvgViewport(
        { x: 0, y: 0, width: 1000, height: 700 },
        { x: 120, y: 80, width: 600, height: 420 },
        0.5,
    );

    assert.deepEqual(interpolated, {
        x: 60,
        y: 40,
        width: 800,
        height: 560,
    });
});

test('resolveSvgViewportFromBounds expands content to the graph aspect ratio', () => {
    const viewport = resolveSvgViewportFromBounds(
        { minX: 120, minY: 140, maxX: 360, maxY: 260 },
        24,
    );

    assert.equal(Math.round((viewport.width / viewport.height) * 1000), 1429);
    assert.ok(viewport.x <= 96);
    assert.ok(viewport.y <= 116);
    assert.ok(viewport.x + viewport.width >= 384);
    assert.ok(viewport.y + viewport.height >= 284);
});

test('expandSvgBounds adds equal bleed on all sides', () => {
    const expanded = expandSvgBounds(
        { minX: 120, minY: 140, maxX: 360, maxY: 260 },
        36,
    );

    assert.deepEqual(expanded, {
        minX: 84,
        minY: 104,
        maxX: 396,
        maxY: 296,
    });
});

test('resolveSvgWheelZoomScale keeps pinch more responsive than mouse wheel', () => {
    const pinchZoomOut = resolveSvgWheelZoomScale(48, 'pinch');
    const wheelZoomOut = resolveSvgWheelZoomScale(48, 'wheel');
    const pinchZoomIn = resolveSvgWheelZoomScale(-48, 'pinch');
    const wheelZoomIn = resolveSvgWheelZoomScale(-48, 'wheel');

    assert.ok(pinchZoomOut < wheelZoomOut);
    assert.ok(pinchZoomIn > wheelZoomIn);
    assert.ok(Math.abs(pinchZoomOut - 1) > Math.abs(wheelZoomOut - 1));
    assert.ok(Math.abs(pinchZoomIn - 1) > Math.abs(wheelZoomIn - 1));
});

test('resolveSvgWheelZoomMode distinguishes pinch from regular wheel input', () => {
    assert.equal(resolveSvgWheelZoomMode(true), 'pinch');
    assert.equal(resolveSvgWheelZoomMode(false), 'wheel');
});

test('resolveSvgWheelPixelDelta normalizes pixel, line, and page deltas', () => {
    assert.equal(resolveSvgWheelPixelDelta(24, 0), 24);
    assert.equal(resolveSvgWheelPixelDelta(3, 1), 48);
    assert.equal(resolveSvgWheelPixelDelta(2, 2), 240);
});
