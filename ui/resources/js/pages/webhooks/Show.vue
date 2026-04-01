<script setup lang="ts">
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, Dot, Globe, Send, WifiOff } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface FlowSummary {
    id: number;
    name: string;
}

interface RunSummary {
    id: number;
    type: string;
}

interface WebhookResponseState {
    status: 'idle' | 'sending' | 'success' | 'error';
    label: string;
    body: string;
}

const props = defineProps<{
    flow: FlowSummary;
    environment: 'production' | 'development';
    run: RunSummary;
    slug: string;
    token: string;
    endpoint: string;
    samplePayload: string;
}>();

const { t } = useI18n();

const payload = ref(props.samplePayload);
const validationError = ref<string | null>(null);
const isSubmitting = ref(false);
const responseState = ref<WebhookResponseState>({
    status: 'idle',
    label: t('flows.webhook_page.response'),
    body: t('flows.webhook_page.response_idle'),
});

const environmentLabel = computed(() => {
    return props.environment === 'production'
        ? t('environments.production')
        : t('environments.development');
});

const responseBadgeVariant = computed(() => {
    switch (responseState.value.status) {
        case 'success':
            return 'default';
        case 'error':
            return 'destructive';
        default:
            return 'secondary';
    }
});

const parsedPayload = computed(() => {
    const trimmedPayload = payload.value.trim();

    if (trimmedPayload === '') {
        return 'null';
    }

    return trimmedPayload;
});

const setResponseState = (
    status: WebhookResponseState['status'],
    label: string,
    body: string,
): void => {
    responseState.value = { status, label, body };
};

const validatePayload = (): boolean => {
    try {
        const normalizedPayload = JSON.stringify(
            JSON.parse(parsedPayload.value),
            null,
            4,
        );

        payload.value = normalizedPayload;
        validationError.value = null;
        return true;
    } catch (error) {
        validationError.value =
            error instanceof Error ? error.message : t('errors.generic');
        setResponseState(
            'error',
            t('flows.webhook_page.invalid_json'),
            validationError.value,
        );

        return false;
    }
};

const submitPayload = async (): Promise<void> => {
    if (isSubmitting.value || !validatePayload()) {
        return;
    }

    isSubmitting.value = true;
    setResponseState(
        'sending',
        t('flows.webhook_page.sending'),
        t('flows.webhook_page.sending'),
    );

    try {
        const response = await fetch(props.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: parsedPayload.value,
        });

        const rawBody = await response.text();
        const formattedBody = rawBody.trim() === '' ? 'null' : rawBody;

        setResponseState(
            response.ok ? 'success' : 'error',
            response.ok
                ? t('flows.webhook_page.response_success')
                : t('flows.webhook_page.response_error'),
            `${response.status} ${response.statusText}\n${formattedBody}`,
        );
    } catch (error) {
        setResponseState(
            'error',
            t('flows.webhook_page.response_network_error'),
            error instanceof Error ? error.message : t('errors.generic'),
        );
    } finally {
        isSubmitting.value = false;
    }
};

async function copyLink(link: string) {
    try {
        await navigator.clipboard.writeText(link);
    } catch (error) {
        console.error('Failed to copy link:', error);
    }
}
</script>

<template>
    <Head :title="`${t('flows.webhook_page.title')} ${slug}`" />

    <main class="flex min-h-dvh bg-background text-foreground">
        <div
            class="container mx-auto min-h-full divide-y border-r border-l border-border"
        >
            <section class="p-4">
                <div
                    class="flex items-center text-xs font-medium text-muted-foreground"
                >
                    <Badge variant="secondary" class="mr-2 w-fit">
                        {{ t('flows.webhook_page.title') }}
                    </Badge>

                    {{ t('flows.webhook_page.meta.flow') }}
                    #{{ flow.id }} "{{ flow.name }}"
                    <Dot size="18" class="mb-px" />
                    {{ t('flows.webhook_page.meta.run') }}
                    #{{ run.id }}
                    <Dot size="18" class="mb-px" />
                    {{ environmentLabel }}
                </div>

                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                    {{ slug }}
                </h1>

                <div class="mt-2 max-w-3xl text-sm leading-6 sm:text-base">
                    {{ t('flows.webhook_page.subtitle') }}
                </div>
            </section>

            <section class="grid grid-cols-[1fr_auto] divide-x">
                <div class="grid gap-0.5 bg-muted/35 px-4 py-3">
                    <div
                        class="flex items-center gap-2 text-xs font-medium text-muted-foreground"
                    >
                        <Globe class="size-4" />
                        {{ t('flows.webhook_page.endpoint') }}
                    </div>
                    <a
                        class="block truncate text-sm leading-6 text-foreground"
                        :href="endpoint"
                        @click.prevent="copyLink(endpoint)"
                    >
                        {{ endpoint }}
                    </a>
                </div>

                <Button
                    type="button"
                    variant="ghost"
                    class="h-full w-44 rounded-none border-none lg:self-stretch"
                    :disabled="isSubmitting"
                    @click="submitPayload"
                >
                    <Spinner v-if="isSubmitting" />
                    <Send v-else class="size-4" />
                    {{
                        isSubmitting
                            ? t('flows.webhook_page.sending')
                            : t('flows.webhook_page.send')
                    }}
                </Button>
            </section>

            <section v-if="validationError">
                <Alert
                    id="webhook-payload-error"
                    variant="destructive"
                    class="border-none"
                >
                    <AlertTitle>
                        {{ t('flows.webhook_page.invalid_json') }}
                    </AlertTitle>
                    <AlertDescription>
                        {{ t('flows.webhook_page.invalid_json_message') }}
                        {{ validationError }}
                    </AlertDescription>
                </Alert>
            </section>

            <section class="grid">
                <FlowCodeEditor
                    v-model="payload"
                    language="json"
                    :disabled="isSubmitting"
                    class="h-[50dvh] border-none bg-background"
                    :tab-size="4"
                    :indent-with-tab="false"
                    bottom-padding="1.25rem"
                    aria-label="JSON payload editor"
                    :aria-describedby="
                        validationError ? 'webhook-payload-error' : undefined
                    "
                />
            </section>

            <section class="grid gap-3 p-4">
                <h2 class="text-lg">
                    {{ t('flows.webhook_page.response') }}
                </h2>

                <div class="grid gap-3">
                    <Alert
                        v-if="responseState.status === 'success'"
                        class="border-emerald-500/25 bg-emerald-500/10 px-4 py-3 text-emerald-700 dark:text-emerald-300"
                    >
                        <CheckCircle2 class="size-4" />
                        <AlertTitle>
                            {{ responseState.label }}
                        </AlertTitle>
                        <AlertDescription>
                            {{
                                t('flows.webhook_page.delivery_steps.response')
                            }}
                        </AlertDescription>
                    </Alert>

                    <Alert
                        v-else-if="responseState.status === 'error'"
                        variant="destructive"
                        class="px-4 py-3"
                    >
                        <WifiOff class="size-4" />
                        <AlertTitle>
                            {{ responseState.label }}
                        </AlertTitle>
                        <AlertDescription>
                            {{ responseState.body }}
                        </AlertDescription>
                    </Alert>

                    <pre
                        aria-live="polite"
                        class="min-h-36 overflow-x-auto rounded-xl border border-border/70 bg-muted/35 p-3 font-mono text-xs leading-6 text-foreground"
                        v-text="responseState.body"
                    />
                </div>
            </section>
        </div>
    </main>
</template>
