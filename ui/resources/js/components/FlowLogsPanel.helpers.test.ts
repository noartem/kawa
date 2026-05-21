import assert from 'node:assert/strict';
import test from 'node:test';

import {
    resolveDispatchHighlightEdgeIds,
    resolveDirectHighlightEdgeIds,
} from './flows/graphHighlights.ts';
import { resolveLogEdgeHighlight } from './FlowLogsPanel.helpers.ts';

test('resolveLogEdgeHighlight maps runtime webhook actor invocations to webhook variants', () => {
    const highlight = resolveLogEdgeHighlight({
        id: 1,
        message: 'Event: flow_runtime_event',
        context: {
            kind: 'actor_invoked',
            actor: 'HandleWebhook',
            trigger_event: 'Webhook',
            payload: {
                slug: 'orders.created',
                payload: {
                    order_id: 42,
                },
            },
        },
    });

    assert.deepEqual(highlight, {
        from: 'Webhook.by(orders.created)',
        to: 'HandleWebhook',
    });
});

test('resolveLogEdgeHighlight maps activity webhook actor invocations to webhook variants', () => {
    const highlight = resolveLogEdgeHighlight({
        id: 2,
        message: 'Event: activity_log',
        context: {
            type: 'actor_invoked',
            details: {
                actor: 'HandleWebhook',
                trigger_event: 'Webhook',
                event_data: {
                    slug: 'orders.created',
                },
            },
        },
    });

    assert.deepEqual(highlight, {
        from: 'Webhook.by(orders.created)',
        to: 'HandleWebhook',
    });
});

test('graph edge highlight resolution matches quoted webhook nodes', () => {
    const graph = {
        nodes: [
            { id: 'Webhook.by("orders.created")', type: 'event' },
            { id: 'HandleWebhook', type: 'actor' },
            { id: 'ProcessedOrder', type: 'event' },
        ],
        edges: [
            { from: 'Webhook.by("orders.created")', to: 'HandleWebhook' },
            { from: 'HandleWebhook', to: 'ProcessedOrder' },
        ],
    };

    assert.deepEqual(
        [...resolveDirectHighlightEdgeIds(graph, {
            from: 'Webhook.by(orders.created)',
            to: 'HandleWebhook',
        })],
        ['Webhook.by("orders.created")->HandleWebhook'],
    );

    assert.deepEqual(
        [...resolveDispatchHighlightEdgeIds(graph, {
            actor: 'HandleWebhook',
            event: 'ProcessedOrder',
            triggerEvent: 'Webhook.by(orders.created)',
        })],
        [
            'Webhook.by("orders.created")->HandleWebhook',
            'HandleWebhook->ProcessedOrder',
        ],
    );
});

test('graph edge highlight resolution matches any dotted event family', () => {
    const graph = {
        nodes: [
            { id: 'Cron.by(0 * * * *)', type: 'event' },
            { id: 'Email.from(system)', type: 'event' },
            { id: 'ScheduleActor', type: 'actor' },
            { id: 'SendDigest', type: 'actor' },
        ],
        edges: [
            { from: 'Cron.by(0 * * * *)', to: 'ScheduleActor' },
            { from: 'Email.from(system)', to: 'SendDigest' },
        ],
    };

    assert.deepEqual(
        [...resolveDirectHighlightEdgeIds(graph, {
            from: 'Cron',
            to: 'ScheduleActor',
        })],
        ['Cron.by(0 * * * *)->ScheduleActor'],
    );

    assert.deepEqual(
        [...resolveDirectHighlightEdgeIds(graph, {
            from: 'Email',
            to: 'SendDigest',
        })],
        ['Email.from(system)->SendDigest'],
    );
});
