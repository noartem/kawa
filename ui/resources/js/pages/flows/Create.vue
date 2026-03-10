<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as flowsIndex, store } from '@/routes/flows';
import type { BreadcrumbItem } from '@/types';
import { Form, Head } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    defaultTemplate: 'blank' | 'cron' | 'webhook';
    defaultTimezone: string;
    timezoneOptions: string[];
}>();

const detectBrowserTimezone = (): string | null => {
    try {
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (typeof timezone === 'string' && timezone.trim().length > 0) {
            return timezone;
        }
    } catch {
        return null;
    }

    return null;
};

const selectedTemplate = ref<'blank' | 'cron' | 'webhook'>(
    props.defaultTemplate,
);
const timezone = ref(detectBrowserTimezone() ?? props.defaultTimezone);

const templates = computed(() => [
    {
        id: 'blank',
        name: t('flows.create.template.blank.name'),
        description: t('flows.create.template.blank.description'),
    },
    {
        id: 'cron',
        name: t('flows.create.template.cron.name'),
        description: t('flows.create.template.cron.description'),
    },
    {
        id: 'webhook',
        name: t('flows.create.template.webhook.name'),
        description: t('flows.create.template.webhook.description'),
    },
]);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: t('nav.flows'),
        href: flowsIndex().url,
    },
    {
        title: t('flows.create.title'),
    },
]);
</script>

<template>
    <Head :title="t('flows.create.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl space-y-8 pt-4 pb-12">
            <div class="space-y-2 px-4">
                <h1 class="text-3xl font-semibold">
                    {{ t('flows.create.title') }}
                </h1>
                <p class="text-muted-foreground">
                    {{ t('flows.create.description') }}
                </p>
            </div>

            <Separator />

            <Form
                v-bind="store.form()"
                class="space-y-6 px-4"
                v-slot="{ errors, processing }"
            >
                <div class="space-y-3">
                    <Label>{{ t('flows.create.template.label') }}</Label>
                    <div class="grid gap-3 md:grid-cols-3">
                        <button
                            type="button"
                            v-for="tmpl in templates"
                            :key="tmpl.id"
                            class="space-y-2 rounded-md border px-4 py-3 text-left transition-colors hover:border-primary"
                            :class="
                                selectedTemplate === tmpl.id
                                    ? 'border-primary ring-2 ring-primary/20'
                                    : 'border-border'
                            "
                            @click="
                                selectedTemplate = tmpl.id as
                                    | 'blank'
                                    | 'cron'
                                    | 'webhook'
                            "
                        >
                            <div class="flex items-center gap-2">
                                <div
                                    class="flex size-4 items-center justify-center rounded-full border-2"
                                    :class="
                                        selectedTemplate === tmpl.id
                                            ? 'border-primary bg-primary'
                                            : 'border-muted-foreground'
                                    "
                                >
                                    <Check
                                        v-if="selectedTemplate === tmpl.id"
                                        class="size-2.5 text-primary-foreground"
                                    />
                                </div>
                                <span class="font-semibold">{{
                                    tmpl.name
                                }}</span>
                            </div>
                            <p class="text-sm text-muted-foreground">
                                {{ tmpl.description }}
                            </p>
                        </button>
                    </div>
                    <input
                        type="hidden"
                        name="template"
                        :value="selectedTemplate"
                    />
                    <p v-if="errors.template" class="text-sm text-destructive">
                        {{ errors.template }}
                    </p>
                </div>

                <Separator />

                <div class="grid gap-4">
                    <div class="space-y-2">
                        <Label for="name">{{ t('forms.name') }}</Label>
                        <Input
                            id="name"
                            type="text"
                            required
                            autofocus
                            autocomplete="off"
                            name="name"
                            :placeholder="t('flows.settings.name_placeholder')"
                        />
                        <p v-if="errors.name" class="text-sm text-destructive">
                            {{ errors.name }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <Label for="description">{{
                            t('flows.settings.description')
                        }}</Label>
                        <Textarea
                            id="description"
                            name="description"
                            :placeholder="
                                t('flows.settings.description_placeholder')
                            "
                            class="min-h-[90px]"
                        />
                    </div>

                    <div class="space-y-2">
                        <Label for="timezone">{{
                            t('flows.settings.timezone')
                        }}</Label>
                        <Input
                            id="timezone"
                            v-model="timezone"
                            type="text"
                            required
                            name="timezone"
                            list="timezone-options-create"
                            :placeholder="
                                t('flows.settings.timezone_placeholder')
                            "
                        />
                        <datalist id="timezone-options-create">
                            <option
                                v-for="timezoneOption in props.timezoneOptions"
                                :key="timezoneOption"
                                :value="timezoneOption"
                            />
                        </datalist>
                        <p
                            v-if="errors.timezone"
                            class="text-sm text-destructive"
                        >
                            {{ errors.timezone }}
                        </p>
                    </div>
                </div>

                <Separator />

                <Button type="submit" class="w-full" :disabled="processing">
                    <Spinner v-if="processing" />
                    {{ t('flows.actions.create') }}
                </Button>
            </Form>
        </div>
    </AppLayout>
</template>
