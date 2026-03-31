<script setup lang="ts">
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, Globe, Send, WifiOff } from 'lucide-vue-next';
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
    run: RunSummary;
    slug: string;
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
    return props.run.type === 'production'
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
</script>

<template>
    <Head :title="`${t('flows.webhook_page.title')} ${slug}`" />

    <main class="min-h-screen bg-background text-foreground">
        <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-6 px-4 py-8 sm:px-6 lg:px-8">
            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(22rem,0.8fr)]">
                <Card class="border-border/70 bg-card/95 shadow-sm">
                    <CardHeader class="gap-4 border-b border-border/70 pb-6">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="space-y-3">
                                <Badge variant="secondary" class="w-fit">
                                    {{ t('flows.webhook_page.title') }}
                                </Badge>
                                <div class="space-y-2">
                                    <CardTitle class="text-3xl font-semibold tracking-tight sm:text-4xl">
                                        {{ slug }}
                                    </CardTitle>
                                    <CardDescription class="max-w-3xl text-sm leading-6 sm:text-base">
                                        {{ t('flows.webhook_page.subtitle') }}
                                    </CardDescription>
                                </div>
                            </div>

                            <div class="grid min-w-44 gap-3 rounded-xl border border-border/70 bg-muted/35 p-4 text-sm">
                                <div>
                                    <div class="text-xs font-medium text-muted-foreground">
                                        {{ t('flows.webhook_page.active_run') }}
                                    </div>
                                    <div class="mt-1 text-xl font-semibold text-foreground">
                                        #{{ run.id }}
                                    </div>
                                </div>
                                <Badge variant="outline" class="w-fit capitalize">
                                    {{ environmentLabel }}
                                </Badge>
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent class="grid gap-6 pt-6">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="rounded-xl border border-border/70 bg-background/70 p-4">
                                <div class="text-xs font-medium text-muted-foreground">
                                    {{ t('flows.webhook_page.meta.flow') }}
                                </div>
                                <div class="mt-2 text-base font-semibold">
                                    {{ flow.name }}
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    Flow #{{ flow.id }}
                                </div>
                            </div>

                            <div class="rounded-xl border border-border/70 bg-background/70 p-4">
                                <div class="text-xs font-medium text-muted-foreground">
                                    {{ t('flows.webhook_page.request_format') }}
                                </div>
                                <div class="mt-2 text-base font-semibold">
                                    <code>application/json</code>
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    POST
                                </div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-border/70 bg-muted/35 p-4">
                            <div class="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                <Globe class="size-4" />
                                {{ t('flows.webhook_page.endpoint') }}
                            </div>
                            <code class="mt-3 block break-all text-sm leading-6 text-foreground">
                                {{ endpoint }}
                            </code>
                        </div>

                        <div class="grid gap-3">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 class="text-sm font-semibold text-foreground">
                                        {{ t('flows.webhook_page.payload_title') }}
                                    </h2>
                                    <p class="text-xs text-muted-foreground">
                                        {{ t('flows.webhook_page.payload_hint') }}
                                    </p>
                                </div>

                                <Button
                                    type="button"
                                    class="min-w-36"
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
                            </div>

                            <FlowCodeEditor
                                v-model="payload"
                                language="json"
                                :disabled="isSubmitting"
                                class="min-h-[26rem] overflow-hidden rounded-xl border border-input bg-background shadow-xs"
                                :tab-size="4"
                                :indent-with-tab="false"
                                bottom-padding="1.25rem"
                                aria-label="JSON payload editor"
                                :aria-describedby="validationError ? 'webhook-payload-error' : undefined"
                            />

                            <Alert
                                v-if="validationError"
                                id="webhook-payload-error"
                                variant="destructive"
                            >
                                <AlertTitle>{{ t('flows.webhook_page.invalid_json') }}</AlertTitle>
                                <AlertDescription>
                                    {{ t('flows.webhook_page.invalid_json_message') }}
                                    {{ validationError }}
                                </AlertDescription>
                            </Alert>
                        </div>
                    </CardContent>
                </Card>

                <div class="grid gap-6">
                    <Card class="border-border/70 bg-card/95 shadow-sm">
                        <CardHeader>
                            <CardTitle class="text-lg">
                                {{ t('flows.webhook_page.delivery_title') }}
                            </CardTitle>
                            <CardDescription>
                                {{ t('flows.webhook_page.response_idle') }}
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="grid gap-3 text-sm text-muted-foreground">
                            <div class="rounded-xl border border-border/70 bg-muted/35 p-4">
                                1. {{ t('flows.webhook_page.delivery_steps.request') }}
                            </div>
                            <div class="rounded-xl border border-border/70 bg-muted/35 p-4">
                                2. {{ t('flows.webhook_page.delivery_steps.handoff') }}
                            </div>
                            <div class="rounded-xl border border-border/70 bg-muted/35 p-4">
                                3. {{ t('flows.webhook_page.delivery_steps.response') }}
                            </div>
                        </CardContent>
                    </Card>

                    <Card class="border-border/70 bg-card/95 shadow-sm">
                        <CardHeader class="flex flex-row items-start justify-between gap-4 space-y-0">
                            <div>
                                <CardTitle class="text-lg">
                                    {{ t('flows.webhook_page.response') }}
                                </CardTitle>
                                <CardDescription>
                                    {{ t('flows.webhook_page.response_idle') }}
                                </CardDescription>
                            </div>

                            <Badge :variant="responseBadgeVariant" aria-live="polite">
                                {{ responseState.label }}
                            </Badge>
                        </CardHeader>
                        <CardContent class="grid gap-4">
                            <Alert
                                v-if="responseState.status === 'success'"
                                class="border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
                            >
                                <CheckCircle2 class="size-4" />
                                <AlertTitle>{{ responseState.label }}</AlertTitle>
                                <AlertDescription>
                                    {{ t('flows.webhook_page.delivery_steps.response') }}
                                </AlertDescription>
                            </Alert>

                            <Alert v-else-if="responseState.status === 'error'" variant="destructive">
                                <WifiOff class="size-4" />
                                <AlertTitle>{{ responseState.label }}</AlertTitle>
                                <AlertDescription>
                                    {{ responseState.body }}
                                </AlertDescription>
                            </Alert>

                            <pre
                                aria-live="polite"
                                class="min-h-56 overflow-x-auto rounded-xl border border-border/70 bg-muted/35 p-4 font-mono text-xs leading-6 text-foreground"
                            >{{ responseState.body }}</pre>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </div>
    </main>
</template>
