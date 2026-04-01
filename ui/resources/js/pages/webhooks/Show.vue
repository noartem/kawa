<script setup lang="ts">
import { useWebhookDispatch } from '@/composables/useWebhookDispatch';
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { show as flowShow } from '@/routes/flows';
import { show as flowDeploymentShow } from '@/routes/flows/deployments';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Dot, Globe, Send, WifiOff } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface FlowSummary {
    id: number;
    name: string;
}

interface RunSummary {
    id: number;
    type: string;
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

const webhookDispatchMessages = computed(() => {
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
} = useWebhookDispatch(() => props.endpoint, props.samplePayload, webhookDispatchMessages);

const environmentLabel = computed(() => {
    return props.environment === 'production'
        ? t('environments.production')
        : t('environments.development');
});

const flowUrl = computed(() => {
    return flowShow({ flow: props.flow.id }).url;
});

const deploymentUrl = computed(() => {
    return flowDeploymentShow({
        flow: props.flow.id,
        deployment: props.run.id,
    }).url;
});

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
                    <Link
                        :href="flowUrl"
                        class="ml-1 underline-offset-4 transition-colors hover:text-foreground hover:underline"
                    >
                        #{{ flow.id }} "{{ flow.name }}"
                    </Link>
                    <Dot size="18" class="mb-px" />
                    {{ t('flows.webhook_page.meta.run') }}
                    <Link
                        :href="deploymentUrl"
                        class="ml-1 underline-offset-4 transition-colors hover:text-foreground hover:underline"
                    >
                        #{{ run.id }}
                    </Link>
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
