<script setup lang="ts">
import FlowCodeMergeView from '@/components/flows/FlowCodeMergeView.vue';
import type { FlowChatConversation } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Bot,
    ChevronDown,
    History,
    Sparkles,
    UserRound,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    chats: FlowChatConversation[];
    formatDate: (value?: string | null) => string;
    formatRecentDate: (value?: string | null) => string;
}>();

const { t } = useI18n();
const expandedChatIds = ref<Set<string>>(new Set());

const toggleChat = (chatId: string): void => {
    const next = new Set(expandedChatIds.value);

    if (next.has(chatId)) {
        next.delete(chatId);
    } else {
        next.add(chatId);
    }

    expandedChatIds.value = next;
};

const isExpanded = (chatId: string): boolean => {
    return expandedChatIds.value.has(chatId);
};

watch(
    () => props.chats,
    (nextChats) => {
        const availableIds = new Set(nextChats.map((chat) => chat.id));
        expandedChatIds.value = new Set(
            [...expandedChatIds.value].filter((chatId) =>
                availableIds.has(chatId),
            ),
        );
    },
    { immediate: true },
);
</script>

<template>
    <section class="space-y-2 px-4 py-3">
        <div>
            <h2 class="text-base font-medium">
                {{ t('flows.past_chats.title') }}
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ t('flows.past_chats.description') }}
            </p>
        </div>

        <div v-if="chats.length" class="grid gap-2">
            <Card
                v-for="chat in chats"
                :key="chat.id"
                class="border-0 bg-muted/10 shadow-none"
            >
                <CardHeader class="gap-2 px-3 py-2.5">
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div>
                            <CardTitle class="text-sm font-medium">
                                {{ chat.title }}
                            </CardTitle>
                            <CardDescription class="mt-1 text-xs">
                                {{ chat.preview || t('common.empty') }}
                            </CardDescription>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <Badge
                                variant="outline"
                                class="h-6 border-0 bg-background/70 px-2 shadow-none"
                            >
                                {{
                                    t('flows.past_chats.messages', {
                                        count: chat.messages_count,
                                    })
                                }}
                            </Badge>
                            <Badge
                                variant="outline"
                                class="h-6 border-0 bg-background/70 px-2 shadow-none"
                            >
                                {{
                                    t('flows.past_chats.updated', {
                                        value: formatRecentDate(
                                            chat.updated_at,
                                        ),
                                    })
                                }}
                            </Badge>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="h-7 w-7 rounded-md"
                                :aria-expanded="isExpanded(chat.id)"
                                @click="toggleChat(chat.id)"
                            >
                                <ChevronDown
                                    class="size-4 transition-transform"
                                    :class="
                                        isExpanded(chat.id) ? 'rotate-180' : ''
                                    "
                                />
                            </Button>
                        </div>
                    </div>
                </CardHeader>

                <CardContent v-if="isExpanded(chat.id)" class="space-y-3 px-3 pt-0 pb-3">
                    <div class="text-xs text-muted-foreground">
                        {{ formatDate(chat.created_at) }}
                    </div>

                    <article
                        v-for="message in chat.messages"
                        :key="message.id"
                        class="rounded-lg bg-background/70 px-3 py-2.5"
                    >
                        <div
                            class="mb-2 flex items-start justify-between gap-3"
                        >
                            <div
                                class="inline-flex items-center gap-1.5 text-sm font-medium"
                            >
                                <component
                                    :is="
                                        message.role === 'assistant'
                                            ? Bot
                                            : UserRound
                                    "
                                    class="size-4 text-muted-foreground"
                                />
                                <span>
                                    {{
                                        message.role === 'assistant'
                                            ? t('flows.editor.chat.assistant')
                                            : t('flows.editor.chat.user')
                                    }}
                                </span>
                                <Badge
                                    v-if="message.kind === 'compact_summary'"
                                    variant="outline"
                                    class="h-6 border-0 bg-amber-500/10 px-2 text-amber-700 shadow-none"
                                >
                                    {{ t('flows.editor.chat.compact_badge') }}
                                </Badge>
                            </div>

                            <span class="text-xs text-muted-foreground">
                                {{ formatRecentDate(message.created_at) }}
                            </span>
                        </div>

                        <p
                            class="text-sm leading-6 whitespace-pre-wrap text-foreground"
                        >
                            {{ message.content }}
                        </p>

                        <div
                            v-if="message.has_code_changes"
                            class="mt-3 space-y-2"
                        >
                            <div
                                class="inline-flex items-center gap-2 text-sm font-medium text-foreground"
                            >
                                <Sparkles
                                    class="size-4 text-muted-foreground"
                                />
                                {{ t('flows.editor.chat.diff_title') }}
                            </div>

                            <div
                                class="overflow-hidden rounded-lg bg-background"
                            >
                                <FlowCodeMergeView
                                    :id="`archived-chat-diff-${message.id}`"
                                    :original-value="message.source_code ?? ''"
                                    :modified-value="
                                        message.proposed_code ?? ''
                                    "
                                    class="h-52 text-xs"
                                />
                            </div>
                        </div>
                    </article>
                </CardContent>
            </Card>
        </div>

        <Card v-else class="border-0 bg-muted/10 shadow-none">
            <CardContent
                class="flex min-h-[180px] flex-col items-center justify-center px-6 text-center"
            >
                <History class="mb-4 size-10 text-muted-foreground/70" />
                <p class="text-sm font-semibold text-foreground">
                    {{ t('flows.past_chats.empty_title') }}
                </p>
                <p class="mt-2 max-w-md text-sm text-muted-foreground">
                    {{ t('flows.past_chats.empty_description') }}
                </p>
            </CardContent>
        </Card>
    </section>
</template>
