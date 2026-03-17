<script setup lang="ts">
import FlowCodeMergeView from '@/components/flows/FlowCodeMergeView.vue';
import type {
    FlowChatConversation,
    FlowChatMessage,
} from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertCircle,
    Bot,
    MessageSquarePlus,
    Minimize2,
    ArrowUp,
    Sparkles,
    UserRound,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const draft = defineModel<string>('draft', { required: true });
const messagesViewport = ref<HTMLDivElement | null>(null);

const props = defineProps<{
    chat: FlowChatConversation | null;
    messages: FlowChatMessage[];
    canUpdate: boolean;
    pending: boolean;
    currentCode: string;
    formatRecentDate: (value?: string | null) => string;
}>();

const emit = defineEmits<{
    send: [];
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

const canApplyProposal = (message: FlowChatMessage): boolean => {
    return (
        Boolean(message.proposed_code) &&
        message.proposed_code !== props.currentCode &&
        props.canUpdate &&
        !props.pending
    );
};

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

const messageSurfaceClass = (message: FlowChatMessage): string => {
    if (message.status === 'error') {
        return 'rounded-lg border border-destructive/30 bg-destructive/8 px-3 py-2.5';
    }

    if (message.status === 'pending') {
        return 'rounded-lg bg-muted/10 px-3 py-2.5';
    }

    return 'rounded-lg bg-muted/10 px-3 py-2.5';
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
</script>

<template>
    <div
        class="h-full min-h-[640px] flex flex-col overflow-hidden rounded-xl border border-border bg-background divide-y"
    >
        <div class="flex flex-wrap items-start gap-2 px-4 pt-3 pb-2">
            <h2 class="text-base font-medium">
                {{ t('flows.editor.chat.title') }}
            </h2>

            <div class="flex-1" />

            <div class="flex flex-wrap gap-1">
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 rounded-md border-0 bg-muted/50 px-2 shadow-none"
                    :disabled="pending || !canUpdate"
                    @click="emit('new-chat')"
                >
                    <MessageSquarePlus class="size-4" />
                    {{ t('flows.editor.chat.new_chat') }}
                </Button>

                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 rounded-md border-0 bg-muted/50 px-2 shadow-none"
                    :disabled="!canCompact"
                    @click="emit('compact')"
                >
                    <Minimize2 class="size-4" />
                    {{ t('flows.editor.chat.compact') }}
                </Button>
            </div>
        </div>

        <div ref="messagesViewport" class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
            <div v-if="hasMessages" class="space-y-3">
                <article
                    v-for="message in messages"
                    :key="message.id"
                    :class="messageSurfaceClass(message)"
                >
                    <div
                        class="mb-2 flex items-start justify-between gap-3"
                    >
                        <div
                            class="inline-flex items-center gap-1.5 text-sm font-medium"
                        >
                            <component
                                :is="
                                    message.status === 'error'
                                        ? AlertCircle
                                        : message.role === 'assistant'
                                        ? Bot
                                        : UserRound
                                "
                                class="size-4"
                                :class="
                                    message.status === 'error'
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                            />
                            <span>
                                {{
                                    message.status === 'error'
                                        ? t('common.error')
                                        : message.role === 'assistant'
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
                            {{
                                message.status === 'pending'
                                    ? ''
                                    : message.status === 'error'
                                    ? t('common.error')
                                    : formatRecentDate(message.created_at)
                            }}
                        </span>
                    </div>

                    <p
                        v-if="message.content"
                        class="text-sm leading-6 whitespace-pre-wrap"
                        :class="
                            message.status === 'error'
                                ? 'text-destructive'
                                : 'text-foreground'
                        "
                    >
                        {{ message.content }}
                    </p>

                    <div
                        v-if="message.status === 'pending'"
                        class="inline-flex items-center gap-1.5 text-muted-foreground"
                        :class="message.content ? 'mt-2' : 'mt-0'"
                    >
                        <Sparkles class="size-3.5 animate-pulse" />
                        <span
                            class="size-1.5 animate-bounce rounded-full bg-current [animation-delay:-0.2s]"
                        />
                        <span
                            class="size-1.5 animate-bounce rounded-full bg-current [animation-delay:-0.1s]"
                        />
                        <span
                            class="size-1.5 animate-bounce rounded-full bg-current"
                        />
                    </div>

                    <div
                        v-if="
                            !message.transient &&
                            message.role === 'assistant' &&
                            message.has_code_changes
                        "
                        class="mt-3 space-y-2"
                    >
                        <div
                            class="flex items-center justify-between gap-2"
                        >
                            <p class="text-sm font-medium text-foreground">
                                {{ t('flows.editor.chat.diff_title') }}
                            </p>

                            <Badge
                                v-if="message.proposed_code === currentCode"
                                variant="outline"
                                class="h-6 border-0 bg-emerald-500/10 px-2 text-emerald-700 shadow-none"
                            >
                                {{ t('flows.editor.chat.applying') }}
                            </Badge>
                        </div>

                        <div
                            class="overflow-hidden rounded-lg bg-background"
                        >
                            <FlowCodeMergeView
                                :id="`chat-diff-${message.id}`"
                                :original-value="
                                    message.source_code ?? currentCode
                                "
                                :modified-value="
                                    message.proposed_code ?? currentCode
                                "
                                class="h-56 text-xs"
                            />
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                            <Button
                                size="sm"
                                class="h-8 rounded-md px-2.5"
                                :disabled="!canApplyProposal(message)"
                                @click="emit('apply-proposal', message)"
                            >
                                <Sparkles class="size-4" />
                                {{ t('flows.editor.chat.apply') }}
                            </Button>

                            <Button
                                size="sm"
                                variant="outline"
                                class="h-8 rounded-md border-0 bg-muted/60 px-2.5 shadow-none"
                                :disabled="!canApplyProposal(message)"
                                @click="
                                    emit('apply-and-save-proposal', message)
                                "
                            >
                                {{ t('flows.editor.chat.apply_and_save') }}
                            </Button>
                        </div>
                    </div>

                </article>
            </div>

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
                class="max-h-60 min-h-9 flex-1 resize-y shadow-none field-sizing-content"
                @keydown="handleComposerKeydown"
            />

            <Button
                class="h-9.5 shrink-0 self-start px-2.5"
                :disabled="
                    pending || !canUpdate || draft.trim().length === 0
                "
                @click="emit('send')"
            >
                <ArrowUp class="size-4" />
            </Button>
        </div>
    </div>
</template>
