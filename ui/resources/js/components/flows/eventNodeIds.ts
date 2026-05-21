const eventVariantPattern = /^([^.\s()]+)\.([^(]+)\(\s*(.*?)\s*\)$/;

const asRecord = (value: unknown): Record<string, unknown> | null => {
    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
        return value as Record<string, unknown>;
    }

    return null;
};

const stringValue = (value: unknown): string | null => {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();

    return trimmed ? trimmed : null;
};

const normalizeVariantArgument = (value: string): string => {
    const trimmed = value.trim();

    if (
        (trimmed.startsWith('"') && trimmed.endsWith('"')) ||
        (trimmed.startsWith("'") && trimmed.endsWith("'"))
    ) {
        return trimmed.slice(1, -1).trim();
    }

    return trimmed;
};

const buildVariantEventId = (
    eventName: string,
    variant: string,
    argument: string,
): string => {
    return `${eventName}.${variant}(${argument})`;
};

const resolveWebhookPayloadSlug = (payload: unknown): string | null => {
    const slug = stringValue(asRecord(payload)?.slug);

    if (!slug) {
        return null;
    }

    const normalizedSlug = normalizeVariantArgument(slug);

    return normalizedSlug.length > 0 ? normalizedSlug : null;
};

export const resolveEventFamilyKey = (eventId: string): string => {
    const normalizedEventId = eventId.trim();
    if (normalizedEventId.length === 0) {
        return normalizedEventId;
    }

    const separatorIndex = normalizedEventId.indexOf('.');

    if (separatorIndex <= 0) {
        return normalizedEventId;
    }

    return normalizedEventId.slice(0, separatorIndex).trim() || normalizedEventId;
};

export const resolveEventGraphNodeId = (
    eventName: unknown,
    payload?: unknown,
): string | null => {
    const normalizedEventName = stringValue(eventName);
    if (!normalizedEventName) {
        return null;
    }

    const existingVariantMatch = normalizedEventName.match(eventVariantPattern);
    if (existingVariantMatch) {
        const normalizedArgument = normalizeVariantArgument(
            existingVariantMatch[3] ?? '',
        );

        return normalizedArgument.length > 0
            ? buildVariantEventId(
                  existingVariantMatch[1],
                  existingVariantMatch[2].trim(),
                  normalizedArgument,
              )
            : normalizedEventName;
    }

    if (normalizedEventName !== 'Webhook' && normalizedEventName !== 'WebhookEvent') {
        return normalizedEventName;
    }

    const slug = resolveWebhookPayloadSlug(payload);

    return slug
        ? buildVariantEventId(normalizedEventName, 'by', slug)
        : normalizedEventName;
};

export const resolveEquivalentEventNodeIds = (eventId: string): string[] => {
    const normalizedEventId = eventId.trim();
    if (normalizedEventId.length === 0) {
        return [];
    }

    const match = normalizedEventId.match(eventVariantPattern);
    if (!match) {
        return [normalizedEventId];
    }

    const normalizedArgument = normalizeVariantArgument(match[3] ?? '');
    if (!normalizedArgument) {
        return [normalizedEventId];
    }

    return [
        normalizedEventId,
        buildVariantEventId(match[1], match[2].trim(), normalizedArgument),
        `${match[1]}.${match[2].trim()}("${normalizedArgument}")`,
        `${match[1]}.${match[2].trim()}('${normalizedArgument}')`,
    ].filter((candidate, index, candidates) => {
        return candidates.indexOf(candidate) === index;
    });
};

export const resolveMatchingEventNodeId = (
    eventId: string,
    nodeIds: Iterable<string>,
): string | null => {
    const availableNodeIds = [...nodeIds];

    for (const candidateId of resolveEquivalentEventNodeIds(eventId)) {
        if (availableNodeIds.includes(candidateId)) {
            return candidateId;
        }
    }

    const familyKey = resolveEventFamilyKey(eventId);
    const familyMatches = availableNodeIds.filter((candidateId) => {
        return resolveEventFamilyKey(candidateId) === familyKey;
    });

    if (familyMatches.length === 0) {
        return null;
    }

    const exactFamilyMatch = familyMatches.find((candidateId) => {
        return candidateId === familyKey;
    });

    if (exactFamilyMatch) {
        return exactFamilyMatch;
    }

    return familyMatches.sort((left, right) => {
        return left.length - right.length || left.localeCompare(right);
    })[0] ?? null;

};
