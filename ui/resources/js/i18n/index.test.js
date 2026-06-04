import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { createI18nInstance } from './index.ts';

describe('createI18nInstance', () => {
    it('defaults to Russian when locale is missing', () => {
        const i18n = createI18nInstance();

        assert.equal(i18n.global.locale.value, 'ru');
    });

    it('falls back to Russian for unsupported locales', () => {
        const i18n = createI18nInstance('de');

        assert.equal(i18n.global.locale.value, 'ru');
    });
});
