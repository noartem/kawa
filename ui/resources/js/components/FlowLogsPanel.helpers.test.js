import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    resolveFreshLogIds,
    resolveDispatchPathHighlight,
    resolveNewLogs,
    resolveStreamReplaySuppression,
    retainVisibleLogIds,
} from './FlowLogsPanel.helpers.ts';

describe('FlowLogsPanel helpers', () => {
    it('keeps prefix-based new log detection when the previous newest id remains', () => {
        const nextLogs = [{ id: 12 }, { id: 11 }, { id: 10 }, { id: 9 }];

        assert.deepEqual(resolveNewLogs(nextLogs, 10), [
            { id: 12 },
            { id: 11 },
        ]);
    });

    it('does not replay the whole window when the previous newest id is gone', () => {
        const nextLogs = [{ id: 12 }, { id: 11 }, { id: 9 }, { id: 8 }];

        assert.deepEqual(resolveNewLogs(nextLogs, 10), [
            { id: 12 },
            { id: 11 },
        ]);
        assert.deepEqual(resolveNewLogs([{ id: 9 }, { id: 8 }], 10), []);
    });

    it('suppresses fresh-log replay when the stream changes', () => {
        const nextLogs = [{ id: 24 }, { id: 23 }, { id: 22 }];

        assert.deepEqual(resolveNewLogs(nextLogs, 11, { streamChanged: true }), []);
        assert.deepEqual(resolveFreshLogIds(nextLogs, 11, { streamChanged: true }), []);
    });

    it('keeps replay suppression pending until replacement logs arrive', () => {
        const firstPass = resolveStreamReplaySuppression(
            'stream-b',
            'stream-a',
            [5, 4],
            [5, 4],
            undefined,
        );

        assert.deepEqual(firstPass, {
            suppressReplay: true,
            pendingStreamKey: 'stream-b',
        });

        const secondPass = resolveStreamReplaySuppression(
            'stream-b',
            'stream-b',
            [20, 19],
            [5, 4],
            firstPass.pendingStreamKey,
        );

        assert.deepEqual(secondPass, {
            suppressReplay: true,
            pendingStreamKey: undefined,
        });
    });

    it('keeps replay suppression pending while the new stream is empty', () => {
        const firstPass = resolveStreamReplaySuppression(
            'stream-b',
            'stream-a',
            [],
            [5, 4],
            undefined,
        );

        assert.deepEqual(firstPass, {
            suppressReplay: true,
            pendingStreamKey: 'stream-b',
        });

        const secondPass = resolveStreamReplaySuppression(
            'stream-b',
            'stream-b',
            [],
            [],
            firstPass.pendingStreamKey,
        );

        assert.deepEqual(secondPass, {
            suppressReplay: true,
            pendingStreamKey: 'stream-b',
        });

        const thirdPass = resolveStreamReplaySuppression(
            'stream-b',
            'stream-b',
            [20, 19],
            [],
            secondPass.pendingStreamKey,
        );

        assert.deepEqual(thirdPass, {
            suppressReplay: true,
            pendingStreamKey: undefined,
        });
    });

    it('extracts dispatch highlights only from runtime dispatch logs', () => {
        const log = {
            id: 15,
            message: 'Event: flow_runtime_event',
            context: {
                kind: 'event_dispatched',
                actor: 'Worker',
                event: 'Done',
                trigger_event: 'Start',
            },
        };

        assert.deepEqual(resolveDispatchPathHighlight(log), {
            actor: 'Worker',
            event: 'Done',
            triggerEvent: 'Start',
        });
        assert.equal(
            resolveDispatchPathHighlight({
                id: 16,
                message: 'Event: actor_message',
                context: log.context,
            }),
            null,
        );
    });

    it('returns fresh log ids without replaying older rows', () => {
        const nextLogs = [{ id: 14 }, { id: 13 }, { id: 12 }, { id: 10 }];

        assert.deepEqual(resolveFreshLogIds(nextLogs, 12), [14, 13]);
        assert.deepEqual(resolveFreshLogIds(nextLogs, null), []);
    });

    it('drops transient ids for logs no longer in view', () => {
        const nextLogs = [{ id: 14 }, { id: 13 }, { id: 12 }];

        assert.deepEqual(
            [...retainVisibleLogIds(nextLogs, new Set([14, 11, 12]))].sort(
                (left, right) => left - right,
            ),
            [12, 14],
        );
    });
});
