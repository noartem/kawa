import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    buildDeploymentGraphMeta,
    countGraphNodesByType,
} from './deploymentDetails.ts';

describe('deployment details helpers', () => {
    it('counts actors and events from graph nodes only', () => {
        const graph = {
            nodes: [
                { id: 'actor.imported', type: 'actor' },
                { id: 'event.created', type: 'event' },
                { id: 'actor.runtime', type: 'ACTOR' },
                { id: 'note.ignored', type: 'note' },
                null,
            ],
        };

        assert.equal(countGraphNodesByType(graph, 'actor'), 2);
        assert.equal(countGraphNodesByType(graph, 'event'), 1);
    });

    it('builds graph meta from deployment timing and counts', () => {
        const meta = buildDeploymentGraphMeta(
            {
                id: 42,
                type: 'production',
                active: false,
                status: 'success',
                started_at: '2026-05-08T09:00:00Z',
                finished_at: '2026-05-08T09:05:00Z',
                created_at: '2026-05-08T08:59:00Z',
                graph: {
                    nodes: [
                        { id: 'event.start', type: 'event' },
                        { id: 'event.done', type: 'event' },
                        { id: 'actor.worker', type: 'actor' },
                    ],
                },
                logs: [],
            },
            (key) => key,
            (status) => `status:${status}`,
            (value) => `date:${value}`,
        );

        assert.deepEqual(meta, {
            actors: 1,
            events: 2,
            status: 'status:success',
            freshnessLabel: 'common.updated_at',
            updatedAt: 'date:2026-05-08T09:05:00Z',
        });
    });
});
