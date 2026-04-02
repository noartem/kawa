<script setup lang="ts">
import FlowCodeMergeView from '@/components/flows/FlowCodeMergeView.vue';
import type { FlowChatMessage } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { renderMarkdown } from '@/lib/markdown';
import {
    AlertCircle,
    Bot,
    ChevronDown,
    ChevronUp,
    Sparkles,
    UserRound,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
    defineProps<{
        messages: FlowChatMessage[];
        currentCode?: string;
        formatRecentDate: (value?: string | null) => string;
        canUpdate?: boolean;
        pending?: boolean;
        interactive?: boolean;
        compact?: boolean;
    }>(),
    {
        currentCode: '',
        canUpdate: false,
        pending: false,
        interactive: false,
        compact: false,
    },
);

const emit = defineEmits<{
    'apply-proposal': [message: FlowChatMessage];
    'apply-and-save-proposal': [message: FlowChatMessage];
    retry: [];
}>();

const { t } = useI18n();
const expandedDiffIds = ref<Set<string>>(new Set());

const expandableMessageIds = computed(() => {
    return props.messages
        .filter(
            (message) =>
                !message.transient &&
                message.role === 'assistant' &&
                message.has_code_changes,
        )
        .map((message) => message.id);
});

const isDiffExpanded = (messageId: string): boolean => {
    return expandedDiffIds.value.has(messageId);
};

const toggleDiff = (messageId: string): void => {
    const nextExpandedIds = new Set(expandedDiffIds.value);

    if (nextExpandedIds.has(messageId)) {
        nextExpandedIds.delete(messageId);
    } else {
        nextExpandedIds.add(messageId);
    }

    expandedDiffIds.value = nextExpandedIds;
};

const syncExpandedDiffIds = (): void => {
    const allowedIds = new Set(expandableMessageIds.value);
    const nextExpandedIds = new Set<string>();

    for (const messageId of expandedDiffIds.value) {
        if (allowedIds.has(messageId)) {
            nextExpandedIds.add(messageId);
        }
    }

    expandedDiffIds.value = nextExpandedIds;
};

watch(expandableMessageIds, syncExpandedDiffIds, { immediate: true });

const messageSurfaceClass = (message: FlowChatMessage): string => {
    if (message.status === 'error') {
        return 'rounded-lg border border-destructive/30 bg-destructive/8 px-3 py-2.5';
    }

    return 'rounded-lg bg-muted/10 px-3 py-2.5';
};

const canApplyProposal = (message: FlowChatMessage): boolean => {
    return (
        props.interactive &&
        Boolean(message.proposed_code) &&
        message.proposed_code !== props.currentCode &&
        props.canUpdate &&
        !props.pending
    );
};

const renderMessageContent = (content: string): string => {
    return renderMarkdown(content);
};

const hasMeaningfulCodeChanges = (message: FlowChatMessage): boolean => {
    return message.has_code_changes;
};

const getDiffSummary = (
    message: FlowChatMessage,
): {
    added: number;
    removed: number;
} => {
    const diff = message.diff ?? '';

    if (diff.trim() === '') {
        return {
            added: 0,
            removed: 0,
        };
    }

    let added = 0;
    let removed = 0;

    for (const line of diff.split('\n')) {
        if (
            line.startsWith('+++') ||
            line.startsWith('---') ||
            line.startsWith('@@')
        ) {
            continue;
        }

        if (line.startsWith('+')) {
            added += 1;
            continue;
        }

        if (line.startsWith('-')) {
            removed += 1;
        }
    }

    return {
        added,
        removed,
    };
};

const getVisibleDiffSummaryItems = (
    message: FlowChatMessage,
): Array<{
    key: 'added' | 'removed';
    count: number;
    label: string;
    className: string;
}> => {
    const summary = getDiffSummary(message);

    return [
        {
            key: 'added',
            count: summary.added,
            label: t('flows.editor.chat.lines_added'),
            className:
                'inline-flex items-center rounded-md bg-emerald-500/10 px-2 py-1 font-semibold text-emerald-700 dark:text-emerald-400',
        },
        {
            key: 'removed',
            count: summary.removed,
            label: t('flows.editor.chat.lines_removed'),
            className:
                'inline-flex items-center rounded-md bg-rose-500/10 px-2 py-1 font-semibold text-rose-700 dark:text-rose-400',
        },
    ].filter((item) => item.count > 0);
};
</script>

<template>
    <div :class="compact ? 'space-y-2.5' : 'space-y-3'">
        <article
            v-for="message in messages"
            :key="message.id"
            :class="messageSurfaceClass(message)"
        >
            <div class="mb-2 flex items-start justify-between gap-3">
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

            <div
                v-if="message.content"
                class="text-sm leading-6 [&_a]:underline [&_a]:underline-offset-2 [&_blockquote]:border-l-2 [&_blockquote]:border-border/60 [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground [&_code]:rounded [&_code]:bg-background/70 [&_code]:px-1 [&_code]:py-0.5 [&_del]:opacity-80 [&_h1]:text-base [&_h1]:font-semibold [&_h2]:text-base [&_h2]:font-semibold [&_h3]:font-semibold [&_li+li]:mt-1 [&_ol]:ml-5 [&_ol]:list-decimal [&_p+p]:mt-3 [&_pre]:mt-3 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-background/80 [&_pre]:px-3 [&_pre]:py-2.5 [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_strong]:font-semibold [&_ul]:ml-5 [&_ul]:list-disc"
                :class="
                    message.status === 'error'
                        ? 'text-destructive'
                        : 'text-foreground'
                "
                v-html="renderMessageContent(message.content)"
            />

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
                <span class="size-1.5 animate-bounce rounded-full bg-current" />
            </div>

            <div
                v-if="
                    interactive &&
                    message.status === 'error' &&
                    message.retryable
                "
                class="mt-3"
            >
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 rounded-md border-0 bg-background/80 px-2.5 shadow-none"
                    :disabled="pending"
                    @click="emit('retry')"
                >
                    {{ t('flows.editor.chat.retry') }}
                </Button>
            </div>

            <div
                v-show="
                    !message.transient &&
                    message.role === 'assistant' &&
                    hasMeaningfulCodeChanges(message)
                "
                class="mt-3"
            >
                <div class="rounded-lg border border-border bg-muted/15">
                    <div
                        class="sticky -top-3 z-10 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-background/95 px-3 py-2 text-xs text-muted-foreground backdrop-blur-sm"
                        :class="
                            isDiffExpanded(message.id)
                                ? 'border-b border-border rounded-b-none'
                                : ''
                        "
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <template v-if="interactive">
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
                            </template>

                            <span
                                v-for="item in getVisibleDiffSummaryItems(
                                    message,
                                )"
                                :key="`${message.id}-${item.key}`"
                                :class="item.className"
                            >
                                {{ item.key === 'added' ? '+' : '-'
                                }}{{ item.count }}
                                {{ item.label }}
                            </span>
                        </div>

                        <Button
                            size="sm"
                            variant="ghost"
                            class="h-8 shrink-0 rounded-md px-2.5 text-muted-foreground shadow-none hover:bg-muted/60"
                            @click="toggleDiff(message.id)"
                        >
                            {{
                                isDiffExpanded(message.id)
                                    ? t('flows.editor.chat.collapse_diff')
                                    : t('flows.editor.chat.expand_diff')
                            }}
                            <component
                                :is="
                                    isDiffExpanded(message.id)
                                        ? ChevronUp
                                        : ChevronDown
                                "
                                class="size-4"
                            />
                        </Button>
                    </div>

                    <div
                        class="grid transition-[grid-template-rows] duration-200 ease-out"
                        :class="
                            isDiffExpanded(message.id)
                                ? 'grid-rows-[1fr]'
                                : 'grid-rows-[0fr]'
                        "
                    >
                        <div
                            class="overflow-hidden rounded-b-lg bg-linear-to-br from-background to-muted/25 transition duration-200 ease-out"
                            :class="
                                isDiffExpanded(message.id)
                                    ? 'opacity-100'
                                    : 'pointer-events-none opacity-0'
                            "
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
                    </div>
                </div>
            </div>
        </article>
    </div>
</template>
