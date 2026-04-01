import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    formatWebhookResponseBody,
    normalizeWebhookPayload,
} from './webhookDispatch.ts';

describe('normalizeWebhookPayload', () => {
    it('treats an empty payload as null', () => {
        assert.deepEqual(normalizeWebhookPayload('   '), {
            formattedPayload: 'null',
            requestBody: 'null',
        });
    });

    it('normalizes valid JSON with indentation', () => {
        assert.deepEqual(
            normalizeWebhookPayload('{"message":"hello","count":2}'),
            {
                formattedPayload:
                    '{\n    "message": "hello",\n    "count": 2\n}',
                requestBody: '{"message":"hello","count":2}',
            },
        );
    });

    it('throws for invalid JSON payloads', () => {
        assert.throws(() => normalizeWebhookPayload('{')); 
    });
});

describe('formatWebhookResponseBody', () => {
    it('renders empty bodies as null', () => {
        assert.equal(formatWebhookResponseBody(202, 'Accepted', '   '), '202 Accepted\nnull');
    });

    it('keeps non-empty response bodies intact', () => {
        assert.equal(
            formatWebhookResponseBody(503, 'Service Unavailable', '{"message":"Failed"}'),
            '503 Service Unavailable\n{"message":"Failed"}',
        );
    });
});
