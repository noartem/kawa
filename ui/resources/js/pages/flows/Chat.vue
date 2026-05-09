<script setup lang="ts">
import FlowConversationMessages from '@/components/flows/editor/FlowConversationMessages.vue';
import type { FlowChatConversation } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as flowShow, index as flowsIndex } from '@/routes/flows';
import {
    index as flowChatIndex,
    show as flowChatShow,
} from '@/routes/flows/chat';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Undo2, Activity } from 'lucide-vue-next';

const props = defineProps<{
    flow: {
        id: number;
        name: string;
    };
    chat: FlowChatConversation;
}>();

const { t, locale } = useI18n();

const parseDateMs = (value?: string | null): number | null => {
    if (!value) {
        return null;
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return null;
    }

    return parsed.getTime();
};

const formatDate = (value?: string | null): string => {
    if (!value) {
        return t('common.empty');
    }

    const parsed = parseDateMs(value);

    if (parsed === null) {
        return value;
    }

    return new Date(parsed).toLocaleString();
};

const relativeTimeFormatter = computed(() => {
    return new Intl.RelativeTimeFormat(locale.value, {
        numeric: 'auto',
    });
});

const formatRecentDate = (value?: string | null): string => {
    const parsed = parseDateMs(value);

    if (parsed === null) {
        return formatDate(value);
    }

    const deltaSeconds = Math.round((parsed - Date.now()) / 1000);
    const absSeconds = Math.abs(deltaSeconds);

    if (absSeconds < 60) {
        return relativeTimeFormatter.value.format(deltaSeconds, 'second');
    }

    const deltaMinutes = Math.round(deltaSeconds / 60);

    if (Math.abs(deltaMinutes) < 60) {
        return relativeTimeFormatter.value.format(deltaMinutes, 'minute');
    }

    const deltaHours = Math.round(deltaMinutes / 60);

    if (Math.abs(deltaHours) < 24) {
        return relativeTimeFormatter.value.format(deltaHours, 'hour');
    }

    const deltaDays = Math.round(deltaHours / 24);

    return relativeTimeFormatter.value.format(deltaDays, 'day');
};

const pageTitle = computed(() => props.chat.title);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: t('nav.flows'),
        href: flowsIndex().url,
    },
    {
        title: t('flows.breadcrumbs.flow', { id: props.flow.id }),
        href: flowShow({ flow: props.flow.id }).url,
    },
    {
        title: t('flows.chats_page.title'),
        href: flowChatIndex({ flow: props.flow.id }).url,
    },
    {
        title: pageTitle.value,
        href: flowChatShow({ flow: props.flow.id, chat: props.chat.id }).url,
    },
]);
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-2">
                    <p class="text-sm text-muted-foreground">
                        {{ props.flow.name }}
                    </p>
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ props.chat.title }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            variant="outline"
                            class="border-border bg-muted/50 text-muted-foreground"
                        >
                            {{
                                t('flows.past_chats.messages', {
                                    count: props.chat.messages_count,
                                })
                            }}
                        </Badge>
                        <Badge
                            variant="outline"
                            class="border-border bg-transparent text-muted-foreground"
                        >
                            {{ t('flows.chats_page.columns.created') }}:
                            {{ formatDate(props.chat.created_at) }}
                        </Badge>
                        <Badge
                            variant="outline"
                            class="border-border bg-transparent text-muted-foreground"
                        >
                            {{ t('flows.chats_page.columns.updated') }}:
                            {{ formatRecentDate(props.chat.updated_at) }}
                        </Badge>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button as-child>
                        <Link
                            :href="flowChatIndex({ flow: props.flow.id }).url"
                        >
                            <Undo2 />
                            {{ t('flows.chats_page.title') }}
                        </Link>
                    </Button>
                    <Button as-child variant="outline">
                        <Link :href="flowShow({ flow: props.flow.id }).url">
                            <Activity />
                            {{
                                t('flows.breadcrumbs.flow', {
                                    id: props.flow.id,
                                })
                            }}
                        </Link>
                    </Button>
                </div>
            </div>

            <section class="rounded-xl border border-border bg-background p-4">
                <FlowConversationMessages
                    v-if="props.chat.messages.length > 0"
                    :messages="props.chat.messages"
                    :format-recent-date="formatRecentDate"
                />

                <div
                    v-else
                    class="flex min-h-80 flex-col items-center justify-center rounded-lg bg-muted/10 px-6 text-center"
                >
                    <p class="text-sm font-medium text-foreground">
                        {{ t('flows.past_chats.empty_title') }}
                    </p>
                    <p class="mt-2 max-w-md text-sm text-muted-foreground">
                        {{ t('flows.past_chats.empty_description') }}
                    </p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
