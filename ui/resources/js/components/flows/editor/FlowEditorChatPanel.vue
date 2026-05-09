<script setup lang="ts">
import FlowConversationMessages from '@/components/flows/editor/FlowConversationMessages.vue';
import type {
    FlowChatConversation,
    FlowChatMessage,
} from '@/components/flows/editor/types';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Link } from '@inertiajs/vue3';
import {
    ArrowUp,
    Bot,
    History,
    MessageSquarePlus,
    Minimize2,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const draft = defineModel<string>('draft', { required: true });
const messagesViewport = ref<HTMLDivElement | null>(null);

const props = withDefaults(
    defineProps<{
        chat: FlowChatConversation | null;
        messages: FlowChatMessage[];
        canUpdate: boolean;
        pending: boolean;
        currentCode: string;
        allChatsUrl?: string | null;
        formatRecentDate: (value?: string | null) => string;
        variant?: 'framed' | 'plain';
    }>(),
    {
        variant: 'framed',
    },
);

const emit = defineEmits<{
    send: [];
    retry: [];
    'new-chat': [];
    compact: [];
    'apply-proposal': [message: FlowChatMessage];
    'apply-and-save-proposal': [message: FlowChatMessage];
}>();

const { t } = useI18n();

const hasMessages = computed(() => {
    return props.messages.length > 0;
});

const canCompact = computed(() => {
    return (
        (props.chat?.messages.length ?? 0) > 0 &&
        !props.pending &&
        props.canUpdate
    );
});

const scrollToLatestMessage = (behavior: ScrollBehavior = 'smooth'): void => {
    if (!messagesViewport.value) {
        return;
    }

    messagesViewport.value.scrollTo({
        top: messagesViewport.value.scrollHeight,
        behavior,
    });
};

const handleComposerKeydown = (event: KeyboardEvent): void => {
    if (event.key !== 'Enter' || event.shiftKey) {
        return;
    }

    event.preventDefault();
    emit('send');
};

const messageScrollSignature = computed(() => {
    return props.messages
        .map((message) => `${message.id}:${message.status ?? 'stable'}`)
        .join('|');
});

watch(messageScrollSignature, async (_next, previous) => {
    await nextTick();
    scrollToLatestMessage(previous ? 'smooth' : 'auto');
});

onMounted(() => {
    scrollToLatestMessage('auto');
});

const containerClass = computed(() => {
    return props.variant === 'plain'
        ? 'flex h-full min-h-[640px] flex-col divide-y overflow-hidden bg-background'
        : 'flex h-full min-h-[640px] flex-col divide-y overflow-hidden rounded-xl border border-border bg-background';
});
</script>

<template>
    <div :class="containerClass">
        <div class="flex flex-wrap items-center gap-2 bg-muted/40 px-4 py-2">
            <h2 class="text-base font-medium">
                {{ t('flows.editor.chat.title') }}
            </h2>

            <div class="flex-1" />

            <div class="flex flex-wrap gap-1">
                <Button
                    v-if="allChatsUrl"
                    as-child
                    variant="outline"
                    size="sm"
                    class="h-7 rounded-md px-2 shadow-none"
                >
                    <Link :href="allChatsUrl">
                        <History class="size-4" />
                        {{ t('flows.editor.chat.history') }}
                    </Link>
                </Button>

                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 rounded-md px-2 shadow-none"
                    :disabled="pending || !canUpdate"
                    @click="emit('new-chat')"
                >
                    <MessageSquarePlus class="size-4" />
                    {{ t('flows.editor.chat.new_chat') }}
                </Button>

                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 rounded-md px-2 shadow-none"
                    :disabled="!canCompact"
                    @click="emit('compact')"
                >
                    <Minimize2 class="size-4" />
                    {{ t('flows.editor.chat.compact') }}
                </Button>
            </div>
        </div>

        <div
            ref="messagesViewport"
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-3"
        >
            <FlowConversationMessages
                v-if="hasMessages"
                interactive
                :messages="messages"
                :current-code="currentCode"
                :can-update="canUpdate"
                :pending="pending"
                :format-recent-date="formatRecentDate"
                @apply-proposal="emit('apply-proposal', $event)"
                @apply-and-save-proposal="
                    emit('apply-and-save-proposal', $event)
                "
                @retry="emit('retry')"
            />

            <div
                v-else
                class="flex h-full min-h-[320px] flex-col items-center justify-center rounded-lg bg-muted/10 px-6 text-center"
            >
                <Bot class="mb-4 size-10 text-muted-foreground/80" />
                <p class="text-sm font-semibold text-foreground">
                    {{ t('flows.editor.chat.empty_title') }}
                </p>
                <p class="mt-2 max-w-md text-sm text-muted-foreground">
                    {{ t('flows.editor.chat.empty_description') }}
                </p>
            </div>
        </div>

        <div class="flex items-start gap-2 px-4 pt-2 pb-3">
            <Textarea
                v-model="draft"
                :disabled="pending || !canUpdate"
                :placeholder="t('flows.editor.chat.placeholder')"
                class="field-sizing-content max-h-60 min-h-9 flex-1 resize-y shadow-none"
                @keydown="handleComposerKeydown"
            />

            <Button
                class="h-9.5 shrink-0 self-start px-2.5"
                :disabled="pending || !canUpdate || draft.trim().length === 0"
                @click="emit('send')"
            >
                <ArrowUp class="size-4" />
            </Button>
        </div>
    </div>
</template>
