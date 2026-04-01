import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    resolveDispatchPathHighlight,
    resolveNewLogs,
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
});
