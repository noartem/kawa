import assert from 'node:assert/strict';
import test from 'node:test';

import {
    readStackedSidePanelsResizeState,
    resolveStackedSidePanelsLayout,
    setStackedSidePanelsResizeQueryParams,
    shouldKeepStackedSidePanelsDividerVisible,
} from './stackedSidePanelsLayout.ts';

test('readStackedSidePanelsResizeState normalizes pixel query values', () => {
    const query = new URLSearchParams(
        'shellWidth=412.6&topHeight=bad&unused=1',
    );

    assert.deepEqual(readStackedSidePanelsResizeState(query), {
        shellWidthPx: 413,
        topHeightPx: null,
    });
});

test('setStackedSidePanelsResizeQueryParams writes and removes resize params', () => {
    const query = new URLSearchParams('tab=editor');

    setStackedSidePanelsResizeQueryParams(query, {
        shellWidthPx: 384.4,
        topHeightPx: 248,
    });

    assert.equal(query.get('tab'), 'editor');
    assert.equal(query.get('shellWidth'), '384');
    assert.equal(query.get('topHeight'), '248');

    setStackedSidePanelsResizeQueryParams(query, {
        shellWidthPx: null,
        topHeightPx: null,
    });

    assert.equal(query.get('shellWidth'), null);
    assert.equal(query.get('topHeight'), null);
});

test('resolveStackedSidePanelsLayout keeps ratio mode until a manual resize exists', () => {
    const layout = resolveStackedSidePanelsLayout({
        activeState: 'both',
        fallbackState: 'both',
        mainRatio: 1.3,
        sideRatio: 1,
        topRatio: 1,
        bottomRatio: 1,
        containerWidthPx: 1200,
        containerHeightPx: 900,
        allowPixelResize: true,
    });

    assert.equal(layout.mainWidth, '56.5217%');
    assert.equal(layout.shellWidth, '43.4783%');
    assert.equal(layout.rowTemplate, 'minmax(0, 1fr) minmax(0, 1fr)');
    assert.equal(layout.topTrackSize, '50.0000%');
    assert.ok(layout.shellWidthPx !== null);
    assert.ok(layout.topHeightPx !== null);
    assert.equal(Math.round(layout.shellWidthPx), 522);
    assert.equal(Math.round(layout.topHeightPx), 450);
    assert.equal(layout.canResizeHorizontally, true);
    assert.equal(layout.canResizeVertically, true);
});

test('resolveStackedSidePanelsLayout switches the touched axes to clamped pixels', () => {
    const layout = resolveStackedSidePanelsLayout({
        activeState: 'both',
        fallbackState: 'both',
        mainRatio: 1.3,
        sideRatio: 1,
        topRatio: 1,
        bottomRatio: 1,
        resizeState: {
            shellWidthPx: 900,
            topHeightPx: 120,
        },
        containerWidthPx: 960,
        containerHeightPx: 640,
        allowPixelResize: true,
    });

    assert.equal(layout.mainWidth, 'calc(100% - 640px)');
    assert.equal(layout.shellWidth, '640px');
    assert.equal(layout.rowTemplate, '160px minmax(0, 1fr)');
    assert.equal(layout.topTrackSize, '160px');
    assert.equal(layout.shellWidthPx, 640);
    assert.equal(layout.topHeightPx, 160);
    assert.equal(layout.shellWidthMinPx, 480);
    assert.equal(layout.shellWidthMaxPx, 640);
    assert.equal(layout.topHeightMinPx, 160);
    assert.equal(layout.topHeightMaxPx, 480);
});

test('resolveStackedSidePanelsLayout collapses desktop shell width when hidden', () => {
    const layout = resolveStackedSidePanelsLayout({
        activeState: 'none',
        fallbackState: 'both',
        mainRatio: 1.3,
        sideRatio: 1,
        topRatio: 1,
        bottomRatio: 1,
        resizeState: {
            shellWidthPx: 560,
            topHeightPx: 280,
        },
        containerWidthPx: 1400,
        containerHeightPx: 900,
        allowPixelResize: true,
    });

    assert.equal(layout.mainWidth, '100%');
    assert.equal(layout.shellWidth, '0px');
    assert.equal(layout.shellDesktopOpacity, '0');
    assert.equal(layout.shellPointerEvents, 'none');
});

test('shouldKeepStackedSidePanelsDividerVisible only keeps the divider during both-panel transitions', () => {
    assert.equal(
        shouldKeepStackedSidePanelsDividerVisible('both', 'top'),
        true,
    );
    assert.equal(
        shouldKeepStackedSidePanelsDividerVisible('bottom', 'both'),
        true,
    );
    assert.equal(
        shouldKeepStackedSidePanelsDividerVisible('both', 'both'),
        false,
    );
    assert.equal(
        shouldKeepStackedSidePanelsDividerVisible('top', 'bottom'),
        false,
    );
    assert.equal(
        shouldKeepStackedSidePanelsDividerVisible('none', 'both'),
        false,
    );
});
