import { createI18n } from 'vue-i18n';
import { messages } from './messages.ts';

export const SUPPORTED_LOCALES = ['en', 'ru'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

const normalizeLocale = (locale?: string | null): SupportedLocale => {
    if (!locale) return 'ru';
    if (SUPPORTED_LOCALES.includes(locale as SupportedLocale)) {
        return locale as SupportedLocale;
    }
    return 'ru';
};

export const createI18nInstance = (locale?: string | null) =>
    createI18n({
        legacy: false,
        globalInjection: true,
        locale: normalizeLocale(locale),
        fallbackLocale: 'ru',
        messages,
    });
