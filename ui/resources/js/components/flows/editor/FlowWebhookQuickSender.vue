<script setup lang="ts">
import { useWebhookDispatch } from '@/composables/useWebhookDispatch';
import { DEFAULT_WEBHOOK_PAYLOAD } from '@/lib/webhookDispatch';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { Check, ChevronDown, Copy, ExternalLink, Send } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
    defineProps<{
        defaultPayload?: string;
        endpoint: string;
        label: string;
    }>(),
    {
        defaultPayload: DEFAULT_WEBHOOK_PAYLOAD,
    },
);

const { t } = useI18n();

const copied = ref(false);
const quickSenderOpen = ref(false);

let copiedTimer: ReturnType<typeof setTimeout> | null = null;

const dispatchMessages = computed(() => {
    return {
        genericError: t('errors.generic'),
        invalidJson: t('flows.webhook_page.invalid_json'),
        response: t('flows.webhook_page.response'),
        responseError: t('flows.webhook_page.response_error'),
        responseIdle: t('flows.webhook_page.response_idle'),
        responseNetworkError: t('flows.webhook_page.response_network_error'),
        responseSuccess: t('flows.webhook_page.response_success'),
        sending: t('flows.webhook_page.sending'),
    };
});

const {
    isSubmitting,
    payload,
    responseState,
    submitPayload,
    validationError,
} = useWebhookDispatch(
    () => props.endpoint,
    props.defaultPayload,
    dispatchMessages,
);

const clearCopiedTimer = (): void => {
    if (copiedTimer === null) {
        return;
    }

    clearTimeout(copiedTimer);
    copiedTimer = null;
};

const copyWithFallback = (value: string): boolean => {
    if (typeof document === 'undefined') {
        return false;
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', 'true');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        document.body.removeChild(textarea);
    }
};

const copyEndpoint = async (): Promise<void> => {
    let didCopy = false;

    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(props.endpoint);
            didCopy = true;
        }
    } catch {
        didCopy = false;
    }

    if (!didCopy) {
        didCopy = copyWithFallback(props.endpoint);
    }

    if (!didCopy) {
        return;
    }

    copied.value = true;
    clearCopiedTimer();
    copiedTimer = setTimeout(() => {
        copied.value = false;
        copiedTimer = null;
    }, 1800);
};

onBeforeUnmount(() => {
    clearCopiedTimer();
});
</script>

<template>
    <div class="grid gap-1.5 text-[11px]" @click.stop @keydown.stop>
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-muted-foreground">
                {{ label }}
            </span>
            <span class="flex-1" />
            <a
                target="_blank"
                rel="noreferrer noopener"
                :href="endpoint"
                class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-emerald-700 transition duration-200 hover:bg-emerald-500/10 hover:text-emerald-600 dark:text-emerald-300"
            >
                <ExternalLink class="size-3" aria-hidden="true" />
                {{ t('flows.editor.discovery.open_webhook') }}
            </a>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-emerald-700 transition duration-200 hover:bg-emerald-500/10 hover:text-emerald-600 dark:text-emerald-300"
                :class="copied ? 'scale-105 bg-emerald-500/15 text-emerald-800 dark:text-emerald-200' : ''"
                @click="copyEndpoint"
            >
                <Check v-if="copied" class="size-3" aria-hidden="true" />
                <Copy v-else class="size-3" aria-hidden="true" />
                {{
                    copied
                        ? t('flows.editor.discovery.webhook_copied')
                        : t('flows.editor.discovery.copy_webhook')
                }}
            </button>
        </div>

        <code
            class="block rounded-md bg-background px-2 py-1.5 leading-relaxed break-all"
        >
            {{ endpoint }}
        </code>

        <Collapsible v-model:open="quickSenderOpen" v-slot="{ open }">
            <div class="overflow-hidden rounded-md border border-border/60 bg-background/80">
                <CollapsibleTrigger as-child>
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-2.5 py-2 text-left text-muted-foreground transition hover:bg-muted/50 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <span class="inline-flex items-center gap-2 font-medium">
                            <Send class="size-3.5" aria-hidden="true" />
                            {{ t('flows.editor.discovery.quick_send') }}
                        </span>
                        <ChevronDown
                            class="size-3.5 transition-transform"
                            :class="open ? 'rotate-180' : ''"
                            aria-hidden="true"
                        />
                    </button>
                </CollapsibleTrigger>

                <CollapsibleContent class="grid gap-2 border-t border-border/60 px-2.5 pt-2.5 pb-2.5">
                    <p class="text-[10px] text-muted-foreground">
                        {{ t('flows.webhook_page.payload_hint') }}
                    </p>

                    <Textarea
                        v-model="payload"
                        rows="6"
                        spellcheck="false"
                        :disabled="isSubmitting"
                        :aria-label="t('flows.webhook_page.payload_title')"
                        class="min-h-[7.5rem] font-mono text-xs leading-5"
                        :aria-invalid="validationError ? 'true' : 'false'"
                    />

                    <p v-if="validationError" class="text-[10px] text-destructive">
                        {{ t('flows.webhook_page.invalid_json_message') }}
                        {{ validationError }}
                    </p>

                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="text-[10px] font-medium"
                            :class="
                                responseState.status === 'success'
                                    ? 'text-emerald-700 dark:text-emerald-300'
                                    : responseState.status === 'error'
                                      ? 'text-destructive'
                                      : 'text-muted-foreground'
                            "
                        >
                            {{ responseState.label }}
                        </span>

                        <Button
                            type="button"
                            size="sm"
                            :disabled="isSubmitting"
                            @click="submitPayload"
                        >
                            <Spinner v-if="isSubmitting" />
                            <Send v-else class="size-3.5" aria-hidden="true" />
                            {{
                                isSubmitting
                                    ? t('flows.webhook_page.sending')
                                    : t('flows.webhook_page.send')
                            }}
                        </Button>
                    </div>

                    <pre
                        aria-live="polite"
                        class="max-h-40 overflow-auto rounded-md border border-border/70 bg-muted/35 p-2 font-mono text-[10px] leading-5 text-foreground"
                        v-text="responseState.body"
                    />
                </CollapsibleContent>
            </div>
        </Collapsible>
    </div>
</template>
