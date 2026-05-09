<script setup lang="ts">
import type { FlowChatConversation } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { show as flowChatShow } from '@/routes/flows/chat';
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    flowId: number;
    chats: FlowChatConversation[];
    allChatsUrl?: string | null;
    formatDate: (value?: string | null) => string;
    formatRecentDate: (value?: string | null) => string;
}>();

const { t } = useI18n();
</script>

<template>
    <section class="space-y-3 p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="text-lg font-semibold">
                    {{ t('flows.past_chats.title') }}
                </h2>
            </div>

            <Link
                v-if="allChatsUrl"
                :href="allChatsUrl"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-700 hover:underline focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                {{ t('flows.past_chats.all') }}
                <ArrowUpRight class="size-4" aria-hidden="true" />
            </Link>
        </div>

        <div class="grid divide-y rounded-xl border border-border">
            <Link
                v-for="chat in chats"
                :key="chat.id"
                :href="flowChatShow({ flow: props.flowId, chat: chat.id }).url"
                class="block w-full px-4 py-3 transition-colors hover:bg-muted/40"
            >
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p class="text-sm font-semibold text-foreground">
                        {{ chat.title }}
                    </p>

                    <Badge
                        variant="outline"
                        class="border-border bg-muted/50 text-muted-foreground"
                    >
                        {{
                            t('flows.past_chats.messages', {
                                count: chat.messages_count,
                            })
                        }}
                    </Badge>

                    <div class="flex-1" />

                    <Badge
                        variant="outline"
                        class="border-border bg-transparent text-muted-foreground"
                    >
                        {{ formatDate(chat.created_at) }}
                        <div
                            class="h-4 border-l border-border"
                            style="transform: translateY(-2px) scaleY(2)"
                        />
                        {{ formatRecentDate(chat.updated_at) }}
                    </Badge>

                    <span
                        class="inline-flex items-center text-muted-foreground"
                    >
                        <ArrowUpRight class="size-4 shrink-0" />
                    </span>
                </div>
            </Link>
        </div>
    </section>
</template>
