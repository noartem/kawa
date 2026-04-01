export const getHistoryAccordionValue = (historyId: number): string => {
    return String(historyId);
};

export const retainExpandedHistoryValues = (
    nextHistoryIds: number[],
    expandedValues: string[],
): string[] => {
    const availableValues = new Set(nextHistoryIds.map(getHistoryAccordionValue));

    return expandedValues.filter((value, index) => {
        return availableValues.has(value) && expandedValues.indexOf(value) === index;
    });
};
