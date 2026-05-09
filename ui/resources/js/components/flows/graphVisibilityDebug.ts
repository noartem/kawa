const FLOW_GRAPH_VISIBILITY_DEBUG_PREFIX = '[flow-graph-visibility]';

const resolveGraphDebugId = (value: unknown): string | null => {
    if (typeof value === 'string' && value.trim().length > 0) {
        return value.trim();
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return null;
};

export const summarizeGraphForDebug = (
    graph: Record<string, unknown> | null | undefined,
): Record<string, unknown> => {
    if (!graph) {
        return {
            hasGraph: false,
            nodeCount: 0,
            edgeCount: 0,
            nodeIds: [],
            edgeIds: [],
        };
    }

    const rawNodes = Array.isArray(graph.nodes) ? graph.nodes : [];
    const rawEdges = Array.isArray(graph.edges) ? graph.edges : [];
    const nodeIds = rawNodes
        .map((rawNode) => {
            if (!rawNode || typeof rawNode !== 'object') {
                return null;
            }

            const node = rawNode as Record<string, unknown>;

            return resolveGraphDebugId(node.id ?? node.name);
        })
        .filter((nodeId): nodeId is string => nodeId !== null);
    const edgeIds = rawEdges
        .map((rawEdge) => {
            if (!rawEdge || typeof rawEdge !== 'object') {
                return null;
            }

            const edge = rawEdge as Record<string, unknown>;
            const from = resolveGraphDebugId(edge.from);
            const to = resolveGraphDebugId(edge.to);

            if (!from || !to) {
                return null;
            }

            return `${from}->${to}`;
        })
        .filter((edgeId): edgeId is string => edgeId !== null);

    return {
        hasGraph: true,
        nodeCount: nodeIds.length,
        edgeCount: edgeIds.length,
        nodeIds,
        edgeIds,
    };
};

export const logFlowGraphVisibility = (
    step: string,
    payload: Record<string, unknown> = {},
): void => {
    if (typeof console === 'undefined') {
        return;
    }

    console.info(FLOW_GRAPH_VISIBILITY_DEBUG_PREFIX, step, payload);
};
