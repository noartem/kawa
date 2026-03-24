<script setup lang="ts">
import FlowConversationMessages from '@/components/flows/editor/FlowConversationMessages.vue';
import type { FlowChatConversation } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { History } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const open = defineModel<boolean>('open', { default: false });

const props = defineProps<{
    chat: FlowChatConversation | null;
    formatDate: (value?: string | null) => string;
    formatRecentDate: (value?: string | null) => string;
}>();

const { t } = useI18n();

const messageCountLabel = computed(() => {
    return t('flows.past_chats.messages', {
        count: props.chat?.messages_count ?? 0,
    });
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="grid h-[85vh] max-h-[85vh] grid-rows-[auto_minmax(0,1fr)] overflow-hidden sm:max-w-4xl"
        >
            <DialogHeader class="space-y-1 pb-1">
                <DialogTitle v-if="chat" class="flex flex-wrap items-center gap-2">
                    <span class="text-base font-semibold text-foreground">
                        {{ chat.title }}
                    </span>
                    <Badge
                        variant="outline"
                        class="border-border bg-muted/50 text-muted-foreground"
                    >
                        {{ messageCountLabel }}
                    </Badge>
                    <Badge
                        variant="outline"
                        class="border-border bg-transparent text-muted-foreground"
                    >
                        {{ t('flows.past_chats.updated', { value: formatDate(chat.updated_at) }) }}
                    </Badge>
                </DialogTitle>
                <DialogTitle v-else>
                    {{ t('flows.past_chats.title') }}
                </DialogTitle>
            </DialogHeader>

            <div v-if="chat" class="min-h-0 overflow-y-auto pr-1">
                <FlowConversationMessages
                    compact
                    :messages="chat.messages"
                    :format-recent-date="formatRecentDate"
                />
            </div>

            <div
                v-else
                class="flex min-h-0 flex-col items-center justify-center rounded-xl border border-dashed border-border bg-muted/10 px-6 text-center"
            >
                <History class="mb-3 size-9 text-muted-foreground/70" />
                <p class="text-sm font-medium text-foreground">
                    {{ t('flows.past_chats.empty_title') }}
                </p>
                <p class="mt-1 max-w-md text-sm text-muted-foreground">
                    {{ t('flows.past_chats.empty_description') }}
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
