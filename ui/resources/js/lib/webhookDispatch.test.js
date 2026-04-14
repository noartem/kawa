import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    EMPTY_WEBHOOK_PAYLOAD_ERROR,
    IDLE_WEBHOOK_RESPONSE_BODY,
    formatWebhookResponseBody,
    isWebhookPayloadEmpty,
    normalizeWebhookPayload,
    shouldRenderWebhookResponse,
} from './webhookDispatch.ts';

describe('normalizeWebhookPayload', () => {
    it('rejects an empty payload', () => {
        assert.throws(
            () => normalizeWebhookPayload('   '),
            (error) => {
                assert.equal(error.message, EMPTY_WEBHOOK_PAYLOAD_ERROR);

                return true;
            },
        );
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

describe('isWebhookPayloadEmpty', () => {
    it('detects empty payloads', () => {
        assert.equal(isWebhookPayloadEmpty('   '), true);
    });

    it('allows non-empty payloads', () => {
        assert.equal(isWebhookPayloadEmpty('{"message":"hello"}'), false);
    });
});

describe('formatWebhookResponseBody', () => {
    it('renders empty bodies as null', () => {
        assert.equal(
            formatWebhookResponseBody(202, 'Accepted', '   '),
            '202 Accepted\nnull',
        );
    });

    it('keeps non-empty response bodies intact', () => {
        assert.equal(
            formatWebhookResponseBody(
                503,
                'Service Unavailable',
                '{"message":"Failed"}',
            ),
            '503 Service Unavailable\n{"message":"Failed"}',
        );
    });
});

describe('quick sender response visibility', () => {
    it('keeps the idle response body empty', () => {
        assert.equal(IDLE_WEBHOOK_RESPONSE_BODY, '');
    });

    it('hides the response panel while idle', () => {
        assert.equal(shouldRenderWebhookResponse('idle'), false);
    });

    it('shows the response panel after a request starts or finishes', () => {
        assert.equal(shouldRenderWebhookResponse('sending'), true);
        assert.equal(shouldRenderWebhookResponse('success'), true);
        assert.equal(shouldRenderWebhookResponse('error'), true);
    });
});
