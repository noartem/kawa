import type { FlowStorageContent } from '@/components/flows/editor/types';

export const formatFlowStorageContent = (
    value: FlowStorageContent | null | undefined,
    spaces = 4,
): string => {
    return JSON.stringify(value ?? {}, null, spaces);
};

export const STORAGE_ERROR_PREVIEW_LENGTH = 120;

export const formatFlowStorageErrorPreview = (
    value: string,
    maxLength = STORAGE_ERROR_PREVIEW_LENGTH,
): string => {
    const normalizedValue = value.trim();

    if (normalizedValue.length <= maxLength) {
        return normalizedValue;
    }

    return `${normalizedValue.slice(0, maxLength - 1).trimEnd()}...`;
};

export const isFlowStorageErrorTruncated = (
    value: string,
    maxLength = STORAGE_ERROR_PREVIEW_LENGTH,
): boolean => {
    return value.trim().length > maxLength;
};
