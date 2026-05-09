import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    hasRenderableContainerSize,
    shouldMountRendererOnResize,
} from './graphRendererState.ts';

describe('graph renderer state helpers', () => {
    it('detects when the renderer container has usable dimensions', () => {
        assert.equal(hasRenderableContainerSize(640, 480), true);
        assert.equal(hasRenderableContainerSize(0, 480), false);
        assert.equal(hasRenderableContainerSize(640, 0), false);
    });

    it('requests a remount only after the component is visible and no renderer exists', () => {
        assert.equal(
            shouldMountRendererOnResize({
                width: 640,
                height: 480,
                hasBeenVisible: true,
                hasActiveRenderer: false,
            }),
            true,
        );

        assert.equal(
            shouldMountRendererOnResize({
                width: 640,
                height: 480,
                hasBeenVisible: false,
                hasActiveRenderer: false,
            }),
            false,
        );

        assert.equal(
            shouldMountRendererOnResize({
                width: 640,
                height: 480,
                hasBeenVisible: true,
                hasActiveRenderer: true,
            }),
            false,
        );

        assert.equal(
            shouldMountRendererOnResize({
                width: 0,
                height: 480,
                hasBeenVisible: true,
                hasActiveRenderer: false,
            }),
            false,
        );
    });
});
