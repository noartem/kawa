import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    describeCronExpression,
    extractCronExpression,
    splitCronEventName,
} from './cronEvent.ts';

describe('cron event helpers', () => {
    it('extracts cron expressions from current and legacy cron event names', () => {
        assert.equal(extractCronExpression('Cron.by("*/15 * * * *")'), '*/15 * * * *');
        assert.equal(
            extractCronExpression('CronEvent.by("0 8 * * *")'),
            '0 8 * * *',
        );
    });

    it('builds human-readable descriptions for cron event names', () => {
        assert.equal(
            describeCronExpression('Cron.by("*/15 * * * *")', 'en'),
            'Every 15 minutes',
        );
    });

    it('splits the visible event name around the highlighted expression', () => {
        assert.deepEqual(splitCronEventName('Cron.by("*/15 * * * *")'), {
            prefix: 'Cron.by("',
            expression: '*/15 * * * *',
            suffix: '")',
        });
    });
});
