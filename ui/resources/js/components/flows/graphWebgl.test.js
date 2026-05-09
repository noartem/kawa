import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { canCreateWebGLContext } from './graphWebgl.ts';

describe('graph WebGL helpers', () => {
    it('returns true when any supported WebGL context can be created', () => {
        const requestedContexts = [];

        const supported = canCreateWebGLContext({
            getContext(contextId) {
                requestedContexts.push(contextId);

                return contextId === 'webgl' ? { kind: contextId } : null;
            },
        });

        assert.equal(supported, true);
        assert.deepEqual(requestedContexts, ['webgl2', 'webgl']);
    });

    it('returns false when all WebGL context lookups fail', () => {
        const supported = canCreateWebGLContext({
            getContext() {
                return null;
            },
        });

        assert.equal(supported, false);
    });

    it('keeps probing after an unsupported context throws', () => {
        const requestedContexts = [];

        const supported = canCreateWebGLContext({
            getContext(contextId) {
                requestedContexts.push(contextId);

                if (contextId === 'webgl2') {
                    throw new Error('webgl2 unavailable');
                }

                return contextId === 'experimental-webgl'
                    ? { kind: contextId }
                    : null;
            },
        });

        assert.equal(supported, true);
        assert.deepEqual(requestedContexts, [
            'webgl2',
            'webgl',
            'experimental-webgl',
        ]);
    });
});
