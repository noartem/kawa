import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    formatFlowStorageContent,
    formatFlowStorageErrorPreview,
    isFlowStorageErrorTruncated,
} from './storageContent.ts';

describe('storage content helpers', () => {
    it('formats missing storage as an empty object', () => {
        assert.equal(formatFlowStorageContent(null), '{}');
    });

    it('formats arrays and respects the indentation level', () => {
        assert.equal(
            formatFlowStorageContent([{ key: 'value' }], 2),
            '[\n  {\n    "key": "value"\n  }\n]',
        );
    });

    it('keeps short storage errors intact', () => {
        assert.equal(
            formatFlowStorageErrorPreview('Invalid JSON payload'),
            'Invalid JSON payload',
        );
        assert.equal(isFlowStorageErrorTruncated('Invalid JSON payload'), false);
    });

    it('truncates long storage errors for compact display', () => {
        const longError = 'x'.repeat(150);

        assert.equal(formatFlowStorageErrorPreview(longError, 10), 'xxxxxxxxx...');
        assert.equal(isFlowStorageErrorTruncated(longError, 10), true);
    });
});
