<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as flowShow, index as flowsIndex } from '@/routes/flows';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    flow: {
        id: number;
        name: string;
    };
    debugUrl: string;
    preview: {
        provider: string;
        model: string;
        base_url?: string | null;
        user_message: string;
        current_code: string;
        instructions: string;
        schema: Record<string, string>;
        active_conversation?: {
            id: string;
            title: string;
        } | null;
        history: Array<{
            id: string;
            role: string;
            agent?: string | null;
            content: string;
            kind?: string | null;
            created_at?: string | null;
        }>;
        request_preview: {
            system_prompt: string;
            history_messages: Array<{
                role: string;
                content: string;
            }>;
            user_message: string;
            structured_output: Record<string, string>;
        };
    };
}>();

const message = ref(props.preview.user_message);
const currentCode = ref(props.preview.current_code);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: 'Flows',
        href: flowsIndex().url,
    },
    {
        title: props.flow.name,
        href: flowShow({ flow: props.flow.id }).url,
    },
    {
        title: 'Chat Debug',
        href: props.debugUrl,
    },
]);

const submit = (): void => {
    router.get(
        props.debugUrl,
        {
            message: message.value,
            current_code: currentCode.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const asPrettyJson = (value: unknown): string => {
    return JSON.stringify(value, null, 2);
};
</script>

<template>
    <Head :title="`Chat Debug - ${flow.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 px-4 py-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold">Chat Debug</h1>
                    <p class="text-sm text-muted-foreground">
                        Inspect the exact Flow chat payload before it goes to the
                        LLM.
                    </p>
                </div>

                <Button variant="outline" as-child>
                    <a :href="flowShow({ flow: flow.id }).url">Back to editor</a>
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Preview input</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="space-y-2">
                        <Label for="debug-message">User message</Label>
                        <Input
                            id="debug-message"
                            v-model="message"
                            placeholder="What do you want the assistant to do?"
                        />
                    </div>

                    <div class="space-y-2">
                        <Label for="debug-code">Current code</Label>
                        <Textarea
                            id="debug-code"
                            v-model="currentCode"
                            class="min-h-[240px] font-mono text-xs"
                        />
                    </div>

                    <Button @click="submit">Refresh preview</Button>
                </CardContent>
            </Card>

            <div class="grid gap-4 xl:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Resolved runtime config</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-muted p-4 text-xs">{{
asPrettyJson({
    provider: preview.provider,
    model: preview.model,
    base_url: preview.base_url,
    active_conversation: preview.active_conversation,
})
                        }}</pre>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Structured output schema</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-muted p-4 text-xs">{{
asPrettyJson(preview.schema)
                        }}</pre>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>System prompt / instructions</CardTitle>
                </CardHeader>
                <CardContent>
                    <pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-muted p-4 text-xs">{{
preview.instructions
                    }}</pre>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Persisted history used for context</CardTitle>
                </CardHeader>
                <CardContent>
                    <pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-muted p-4 text-xs">{{
asPrettyJson(preview.history)
                    }}</pre>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Final request preview</CardTitle>
                </CardHeader>
                <CardContent>
                    <pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-muted p-4 text-xs">{{
asPrettyJson(preview.request_preview)
                    }}</pre>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
