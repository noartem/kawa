import cronstrue from 'cronstrue';
import 'cronstrue/locales/ru.js';

export const extractCronExpression = (name: string): string | null => {
    const prefix = ['Cron.by(', 'CronEvent.by('].find((value) =>
        name.startsWith(value),
    );

    if (!prefix || !name.endsWith(')')) {
        return null;
    }

    const rawExpression = name.slice(prefix.length, -1).trim();
    if (!rawExpression) {
        return null;
    }

    if (
        (rawExpression.startsWith('"') && rawExpression.endsWith('"')) ||
        (rawExpression.startsWith("'") && rawExpression.endsWith("'"))
    ) {
        const unwrappedExpression = rawExpression.slice(1, -1).trim();

        return unwrappedExpression.length > 0 ? unwrappedExpression : null;
    }

    return rawExpression;
};

export const describeCronExpression = (
    name: string,
    locale: 'en' | 'ru',
): string | null => {
    const cronExpression = extractCronExpression(name);
    if (!cronExpression) {
        return null;
    }

    try {
        return cronstrue.toString(cronExpression, { locale });
    } catch {
        return null;
    }
};

export const splitCronEventName = (
    name: string,
): { prefix: string; expression: string; suffix: string } | null => {
    const cronExpression = extractCronExpression(name);
    if (!cronExpression) {
        return null;
    }

    const expressionIndex = name.indexOf(cronExpression);
    if (expressionIndex === -1) {
        return null;
    }

    return {
        prefix: name.slice(0, expressionIndex),
        expression: cronExpression,
        suffix: name.slice(expressionIndex + cronExpression.length),
    };
};
