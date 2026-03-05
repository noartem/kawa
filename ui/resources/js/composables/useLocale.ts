import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

export const useLocale = () => {
    const page = usePage();
    const { locale } = useI18n();

    const currentLocale = computed(() => locale.value);
    const supportedLocales = computed(
        () => (page.props.locales as string[] | undefined) ?? [],
    );

    const setLocale = (nextLocale: string) => {
        if (!nextLocale || nextLocale === locale.value) return;

        locale.value = nextLocale;
        document.documentElement.lang = nextLocale;

        router.put(
            '/settings/locale',
            { locale: nextLocale },
            { preserveScroll: true, preserveState: true },
        );
    };

    return {
        currentLocale,
        supportedLocales,
        setLocale,
    };
};
