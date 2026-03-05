<script setup lang="ts">
import { createFlowCodeEditorExtensions } from '@/components/flows/codeEditorExtensions';
import { useDarkThemeClass } from '@/composables/useDarkThemeClass';
import { computed, useAttrs } from 'vue';
import { Codemirror } from 'vue-codemirror';

defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(
    defineProps<{
        modelValue?: string;
        disabled?: boolean;
        language?: 'python' | 'text';
        indentWithTab?: boolean;
        tabSize?: number;
        bottomPadding?: string;
        lineWrapping?: boolean;
    }>(),
    {
        modelValue: '',
        disabled: false,
        language: 'python',
        indentWithTab: true,
        tabSize: 4,
        bottomPadding: '0.75rem',
        lineWrapping: true,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const attrs = useAttrs();
const { isDarkThemeClass } = useDarkThemeClass();

const codeEditorExtensions = computed(() => {
    return createFlowCodeEditorExtensions({
        language: props.language,
        bottomPadding: props.bottomPadding,
        lineWrapping: props.lineWrapping,
        isDarkTheme: isDarkThemeClass.value,
    });
});

const themeKey = computed(() => {
    return isDarkThemeClass.value ? 'dark' : 'light';
});

const model = computed({
    get: () => {
        return props.modelValue ?? '';
    },
    set: (value: string) => {
        emit('update:modelValue', value);
    },
});
</script>

<template>
    <Codemirror
        :key="themeKey"
        v-model="model"
        :disabled="disabled"
        :indent-with-tab="indentWithTab"
        :tab-size="tabSize"
        :extensions="codeEditorExtensions"
        class="flow-code-editor min-h-[9rem] overflow-hidden text-sm"
        v-bind="attrs"
    />
</template>
