<script setup lang="ts">
import { createFlowCodeEditorExtensions } from '@/components/flows/codeEditorExtensions';
import { useDarkThemeClass } from '@/composables/useDarkThemeClass';
import { unifiedMergeView } from '@codemirror/merge';
import { computed, ref, useAttrs, watch } from 'vue';
import { Codemirror } from 'vue-codemirror';

defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(
    defineProps<{
        originalValue?: string | null;
        modifiedValue?: string | null;
        language?: 'python' | 'text';
        lineWrapping?: boolean;
    }>(),
    {
        originalValue: '',
        modifiedValue: '',
        language: 'python',
        lineWrapping: true,
    },
);

const attrs = useAttrs();
const mergeViewKey = ref(0);
const { isDarkThemeClass } = useDarkThemeClass();

watch(
    () => [props.originalValue, props.language, isDarkThemeClass.value],
    () => {
        mergeViewKey.value += 1;
    },
);

const codeEditorExtensions = computed(() => {
    return createFlowCodeEditorExtensions({
        language: props.language,
        lineWrapping: props.lineWrapping,
        isDarkTheme: isDarkThemeClass.value,
        extraExtensions: unifiedMergeView({
            original: props.originalValue ?? '',
            gutter: true,
            highlightChanges: true,
            allowInlineDiffs: true,
            mergeControls: false,
        }),
    });
});
</script>

<template>
    <Codemirror
        :key="mergeViewKey"
        :model-value="modifiedValue ?? ''"
        :disabled="true"
        :indent-with-tab="true"
        :tab-size="4"
        :extensions="codeEditorExtensions"
        class="flow-code-merge-view min-h-[9rem] overflow-hidden text-sm"
        v-bind="attrs"
    />
</template>
