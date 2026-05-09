import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    resolveStackedSidePanelsLayout,
    resolveStackedSidePanelsState,
    shouldAnimateStackedSidePanelsInternals,
} from './stackedSidePanelsLayout.ts';

describe('stacked side panels layout helpers', () => {
    it('maps active flags to layout states', () => {
        assert.equal(resolveStackedSidePanelsState(false, false), 'none');
        assert.equal(resolveStackedSidePanelsState(true, false), 'top');
        assert.equal(resolveStackedSidePanelsState(false, true), 'bottom');
        assert.equal(resolveStackedSidePanelsState(true, true), 'both');
    });

    it('keeps the shell as one sliding block when the last panel closes', () => {
        const layout = resolveStackedSidePanelsLayout({
            activeState: 'none',
            fallbackState: 'top',
        });

        assert.deepEqual(layout, {
            hasSidePanels: false,
            visibleState: 'top',
            mainWidth: '100%',
            shellWidth: '40.0000%',
            shellTransform: 'translate3d(100%, 0, 0)',
            shellPointerEvents: 'none',
            rowTemplate: 'minmax(0, 1fr) minmax(0, 0fr)',
            topTransform: 'translate3d(0, 0, 0)',
            bottomTransform: 'translate3d(0, 0, 0)',
            topOpacity: '1',
            bottomOpacity: '1',
            topPointerEvents: 'none',
            bottomPointerEvents: 'none',
            dividerVisible: false,
            mobileShellHeight: '0px',
        });
    });

    it('splits the shell by the provided ratios when both panels are active', () => {
        const layout = resolveStackedSidePanelsLayout({
            activeState: 'both',
            fallbackState: 'both',
            mainRatio: 3,
            sideRatio: 2,
            topRatio: 2,
            bottomRatio: 1,
        });

        assert.equal(layout.mainWidth, '60.0000%');
        assert.equal(layout.shellWidth, '40.0000%');
        assert.equal(layout.rowTemplate, 'minmax(0, 2fr) minmax(0, 1fr)');
        assert.equal(layout.dividerVisible, true);
        assert.equal(layout.topPointerEvents, 'auto');
        assert.equal(layout.bottomPointerEvents, 'auto');
    });

    it('slides the bottom panel down while the top panel expands', () => {
        const layout = resolveStackedSidePanelsLayout({
            activeState: 'top',
            fallbackState: 'both',
        });

        assert.equal(layout.visibleState, 'top');
        assert.equal(layout.rowTemplate, 'minmax(0, 1fr) minmax(0, 0fr)');
        assert.equal(layout.topTransform, 'translate3d(0, 0, 0)');
        assert.equal(layout.bottomTransform, 'translate3d(0, 100%, 0)');
        assert.equal(layout.bottomOpacity, '0');
        assert.equal(layout.bottomPointerEvents, 'none');
    });

    it('normalizes invalid ratios back to the default proportions', () => {
        const layout = resolveStackedSidePanelsLayout({
            activeState: 'both',
            fallbackState: 'both',
            mainRatio: 0,
            sideRatio: Number.NaN,
            topRatio: -10,
            bottomRatio: Infinity,
        });

        assert.equal(layout.mainWidth, '60.0000%');
        assert.equal(layout.shellWidth, '40.0000%');
        assert.equal(layout.rowTemplate, 'minmax(0, 1fr) minmax(0, 1fr)');
    });

    it('skips internal row and panel transitions when the shell hides or reopens', () => {
        assert.equal(
            shouldAnimateStackedSidePanelsInternals('bottom', 'none'),
            false,
        );
        assert.equal(
            shouldAnimateStackedSidePanelsInternals('none', 'top'),
            false,
        );
        assert.equal(
            shouldAnimateStackedSidePanelsInternals('top', 'bottom'),
            true,
        );
        assert.equal(
            shouldAnimateStackedSidePanelsInternals('both', 'top'),
            true,
        );
    });
});
