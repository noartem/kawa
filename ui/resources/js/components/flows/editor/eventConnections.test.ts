import assert from 'node:assert/strict';
import test from 'node:test';

import {
    collectRelatedEventIdsById,
    connectRelatedEventsInGraph,
} from './eventConnections.ts';

test('connectRelatedEventsInGraph links dotted event variants by their first segment', () => {
    const graph = {
        nodes: [
            { id: 'ImapEmailReceived', type: 'event' },
            { id: 'ImapEmailReceived.by(@susu.ru)', type: 'event' },
            { id: 'ImapEmailReceived.by(...)', type: 'event' },
            { id: 'Cron', type: 'event' },
            { id: 'Cron.by(0 * * * *)', type: 'event' },
            { id: 'Email.from(admin@example.com)', type: 'event' },
            { id: 'Email.sent(welcome)', type: 'event' },
            { id: 'MailActor', type: 'actor' },
        ],
        edges: [
            { from: 'ImapEmailReceived', to: 'MailActor' },
            { from: 'MailActor', to: 'ImapEmailReceived.by(@susu.ru)' },
        ],
    };

    const connectedGraph = connectRelatedEventsInGraph(graph);

    assert.notEqual(connectedGraph, null);
    assert.deepEqual(connectedGraph?.edges, [
        { from: 'ImapEmailReceived', to: 'MailActor' },
        { from: 'MailActor', to: 'ImapEmailReceived.by(@susu.ru)' },
        { from: 'ImapEmailReceived', to: 'ImapEmailReceived.by(...)' },
        { from: 'ImapEmailReceived', to: 'ImapEmailReceived.by(@susu.ru)' },
        { from: 'Cron', to: 'Cron.by(0 * * * *)' },
        { from: 'Email.from(admin@example.com)', to: 'Email.sent(welcome)' },
    ]);
});

test('collectRelatedEventIdsById exposes symmetric related event lists for dotted names', () => {
    const relatedEvents = collectRelatedEventIdsById({
        nodes: [
            { id: 'Webhook', type: 'event' },
            { id: 'Webhook.by(order.created)', type: 'event' },
            { id: 'Webhook.retry(order.updated)', type: 'event' },
            { id: 'SendEmail', type: 'actor' },
        ],
    });

    assert.deepEqual(relatedEvents.get('Webhook'), [
        'Webhook.by(order.created)',
        'Webhook.retry(order.updated)',
    ]);
    assert.deepEqual(relatedEvents.get('Webhook.by(order.created)'), [
        'Webhook',
        'Webhook.retry(order.updated)',
    ]);
    assert.deepEqual(relatedEvents.get('Webhook.retry(order.updated)'), [
        'Webhook',
        'Webhook.by(order.created)',
    ]);
    assert.equal(relatedEvents.has('SendEmail'), false);
});
