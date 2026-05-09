<script setup lang="ts">
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import {
    formatFlowStorageErrorPreview,
    isFlowStorageErrorTruncated,
} from '@/components/flows/editor/storageContent';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Dot } from 'lucide-vue-next';

const content = defineModel<string>('content', { required: true });

const props = withDefaults(
    defineProps<{
        editorId: string;
        readonly?: boolean;
        readonlyReason?: string | null;
        saving?: boolean;
        dirty?: boolean;
        errorMessage?: string | null;
        bottomPadding?: string;
        showSaveButton?: boolean;
    }>(),
    {
        readonly: false,
        readonlyReason: null,
        saving: false,
        dirty: false,
        errorMessage: null,
        bottomPadding: '4rem',
        showSaveButton: false,
    },
);

defineEmits<{
    save: [];
}>();

const { t } = useI18n();

const saveDisabled = computed(() => {
    return (
        props.readonly ||
        props.saving ||
        !props.dirty ||
        Boolean(props.errorMessage)
    );
});

const errorPreview = computed(() => {
    if (!props.errorMessage) {
        return null;
    }

    return formatFlowStorageErrorPreview(props.errorMessage);
});

const errorIsTruncated = computed(() => {
    if (!props.errorMessage) {
        return false;
    }

    return isFlowStorageErrorTruncated(props.errorMessage);
});
</script>

<template>
    <div class="flex h-full flex-col divide-y">
        <div class="flex items-center gap-2 bg-muted/40 px-4 py-2">
            <p class="shrink-0 text-sm font-semibold text-foreground">
                {{ t('flows.editor.storage.title') }}
            </p>

            <template v-if="errorPreview">
                <Dot
                    class="-mx-2 mt-0.5 size-6 shrink-0 text-muted-foreground"
                />

                <Tooltip>
                    <TooltipTrigger as-child>
                        <p
                            class="max-w-full shrink truncate text-sm text-destructive"
                        >
                            {{ errorPreview }}
                        </p>
                    </TooltipTrigger>
                    <TooltipContent
                        v-if="errorIsTruncated"
                        side="bottom"
                        class="max-w-md text-xs wrap-break-word"
                    >
                        {{ props.errorMessage }}
                    </TooltipContent>
                </Tooltip>
            </template>

            <template v-else-if="props.readonlyReason">
                <Dot
                    class="-mx-2 mt-0.5 size-6 shrink-0 text-muted-foreground"
                />

                <p class="shrink text-sm text-muted-foreground">
                    {{ props.readonlyReason }}
                </p>
            </template>

            <div class="grow" />

            <Button
                v-if="props.showSaveButton"
                variant="outline"
                size="sm"
                class="h-7 shrink-0 rounded-md px-2 shadow-none"
                :disabled="saveDisabled"
                @click="$emit('save')"
            >
                <Spinner v-if="props.saving" class="size-4" />
                {{ t('flows.editor.storage.save') }}
            </Button>
        </div>

        <div
            class="min-h-0 flex-1 overflow-hidden bg-linear-to-br from-background to-muted/25"
        >
            <FlowCodeEditor
                :id="props.editorId"
                v-model="content"
                language="json"
                :disabled="props.readonly"
                class="h-full"
                :bottom-padding="props.bottomPadding"
            />
        </div>
    </div>
</template>
