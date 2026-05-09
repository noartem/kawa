import {
    logFlowGraphVisibility,
    summarizeGraphForDebug,
} from './graphVisibilityDebug.ts';

export const HIDDEN_GRAPH_NODE_QUERY_PARAM = 'hidden';

const resolveGraphId = (value: unknown): string | null => {
    if (typeof value === 'string' && value.trim().length > 0) {
        return value.trim();
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return null;
};

export const normalizeHiddenGraphNodeIds = (
    hiddenNodeIds: Iterable<string>,
): string[] => {
    return [...new Set(Array.from(hiddenNodeIds, (nodeId) => nodeId.trim()))]
        .filter((nodeId) => nodeId.length > 0)
        .sort((left, right) => left.localeCompare(right));
};

export const parseHiddenGraphNodeIds = (
    searchParams: URLSearchParams,
): string[] => {
    const hiddenNodeIds = normalizeHiddenGraphNodeIds(
        searchParams.getAll(HIDDEN_GRAPH_NODE_QUERY_PARAM),
    );

    logFlowGraphVisibility('graphVisibility.parseHiddenGraphNodeIds', {
        rawHiddenParams: searchParams.getAll(HIDDEN_GRAPH_NODE_QUERY_PARAM),
        hiddenNodeIds,
    });

    return hiddenNodeIds;
};

export const setHiddenGraphNodeQueryParams = (
    searchParams: URLSearchParams,
    hiddenNodeIds: Iterable<string>,
): void => {
    const normalizedHiddenNodeIds = normalizeHiddenGraphNodeIds(hiddenNodeIds);

    searchParams.delete(HIDDEN_GRAPH_NODE_QUERY_PARAM);

    for (const hiddenNodeId of normalizedHiddenNodeIds) {
        searchParams.append(HIDDEN_GRAPH_NODE_QUERY_PARAM, hiddenNodeId);
    }

    logFlowGraphVisibility('graphVisibility.setHiddenGraphNodeQueryParams', {
        hiddenNodeIds: normalizedHiddenNodeIds,
        queryString: searchParams.toString(),
    });
};

export const filterFlowGraphByHiddenNodeIds = (
    graph: Record<string, unknown> | null | undefined,
    hiddenNodeIds: Iterable<string>,
): Record<string, unknown> | null => {
    const normalizedHiddenNodeIds = normalizeHiddenGraphNodeIds(hiddenNodeIds);

    if (!graph) {
        logFlowGraphVisibility('graphVisibility.filterFlowGraphByHiddenNodeIds', {
            hiddenNodeIds: normalizedHiddenNodeIds,
            inputGraph: summarizeGraphForDebug(graph),
            outputGraph: summarizeGraphForDebug(null),
        });

        return null;
    }

    if (normalizedHiddenNodeIds.length === 0) {
        logFlowGraphVisibility('graphVisibility.filterFlowGraphByHiddenNodeIds', {
            hiddenNodeIds: normalizedHiddenNodeIds,
            inputGraph: summarizeGraphForDebug(graph),
            outputGraph: summarizeGraphForDebug(graph),
        });

        return graph;
    }

    const hiddenNodeIdSet = new Set(normalizedHiddenNodeIds);
    const rawNodes = Array.isArray(graph.nodes) ? graph.nodes : [];
    const rawEdges = Array.isArray(graph.edges) ? graph.edges : [];

    const filteredGraph = {
        ...graph,
        nodes: rawNodes.filter((rawNode) => {
            if (!rawNode || typeof rawNode !== 'object') {
                return false;
            }

            const node = rawNode as Record<string, unknown>;
            const nodeId = resolveGraphId(node.id ?? node.name);

            return nodeId !== null && !hiddenNodeIdSet.has(nodeId);
        }),
        edges: rawEdges.filter((rawEdge) => {
            if (!rawEdge || typeof rawEdge !== 'object') {
                return false;
            }

            const edge = rawEdge as Record<string, unknown>;
            const from = resolveGraphId(edge.from);
            const to = resolveGraphId(edge.to);

            if (!from || !to) {
                return false;
            }

            return !hiddenNodeIdSet.has(from) && !hiddenNodeIdSet.has(to);
        }),
    };

    logFlowGraphVisibility('graphVisibility.filterFlowGraphByHiddenNodeIds', {
        hiddenNodeIds: normalizedHiddenNodeIds,
        inputGraph: summarizeGraphForDebug(graph),
        outputGraph: summarizeGraphForDebug(filteredGraph),
    });

    return filteredGraph;
};
