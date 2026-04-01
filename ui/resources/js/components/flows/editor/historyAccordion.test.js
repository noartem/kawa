import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    getHistoryAccordionValue,
    retainExpandedHistoryValues,
} from './historyAccordion.ts';

describe('history accordion helpers', () => {
    it('maps numeric history ids to string accordion values', () => {
        assert.equal(getHistoryAccordionValue(42), '42');
    });

    it('retains only currently available expanded values', () => {
        assert.deepEqual(
            retainExpandedHistoryValues([2, 3, 4], ['1', '2', '4']),
            ['2', '4'],
        );
    });

    it('deduplicates repeated expanded values while preserving order', () => {
        assert.deepEqual(
            retainExpandedHistoryValues([4, 5], ['4', '4', '5']),
            ['4', '5'],
        );
    });
});
