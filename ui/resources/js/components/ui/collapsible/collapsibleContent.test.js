import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { collapsibleContentAnimationClass } from './collapsibleContent.ts';

describe('collapsible content animation classes', () => {
    it('include expand, collapse, and clipping styles', () => {
        assert.match(
            collapsibleContentAnimationClass,
            /data-\[state=closed\]:animate-accordion-up/,
        );
        assert.match(
            collapsibleContentAnimationClass,
            /data-\[state=open\]:animate-accordion-down/,
        );
        assert.match(collapsibleContentAnimationClass, /overflow-hidden/);
    });
});
