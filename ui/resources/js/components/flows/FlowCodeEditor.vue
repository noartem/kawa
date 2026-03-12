<script setup lang="ts">
import { createFlowCodeEditorExtensions } from '@/components/flows/codeEditorExtensions';
import { useDarkThemeClass } from '@/composables/useDarkThemeClass';
import { EditorSelection, StateEffect, StateField } from '@codemirror/state';
import { Decoration, type DecorationSet, EditorView } from '@codemirror/view';
import { computed, onBeforeUnmount, ref, useAttrs } from 'vue';
import { Codemirror } from 'vue-codemirror';

const highlightLineEffect = StateEffect.define<number>();
const clearHighlightedLineEffect = StateEffect.define<void>();

const highlightedLineField = StateField.define<DecorationSet>({
    create() {
        return Decoration.none;
    },
    update(decorations, transaction) {
        let nextDecorations = decorations.map(transaction.changes);

        for (const effect of transaction.effects) {
            if (effect.is(clearHighlightedLineEffect)) {
                nextDecorations = Decoration.none;
                continue;
            }

            if (!effect.is(highlightLineEffect)) {
                continue;
            }

            const lineNumber = Math.min(
                Math.max(effect.value, 1),
                transaction.state.doc.lines,
            );
            const line = transaction.state.doc.line(lineNumber);
            nextDecorations = Decoration.set([
                Decoration.line({ class: 'cm-flow-jump-line' }).range(
                    line.from,
                ),
            ]);
        }

        return nextDecorations;
    },
    provide(field) {
        return EditorView.decorations.from(field);
    },
});

const highlightedLineTheme = EditorView.theme({
    '.cm-flow-jump-line': {
        backgroundColor: 'rgba(250, 204, 21, 0.28)',
        boxShadow: 'inset 3px 0 0 rgba(234, 179, 8, 0.95)',
        transition: 'background-color 220ms ease, box-shadow 220ms ease',
    },
});

interface FlowCodeEditorReadyPayload {
    view: EditorView;
}

interface FlowCodeEditorExpose {
    focusLine: (line: number, flash?: boolean) => boolean;
}

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
const editorView = ref<EditorView | null>(null);

let flashResetTimer: ReturnType<typeof setTimeout> | null = null;

const clearFlashResetTimer = (): void => {
    if (flashResetTimer === null) {
        return;
    }

    clearTimeout(flashResetTimer);
    flashResetTimer = null;
};

const clearHighlightedLine = (): void => {
    const view = editorView.value;
    if (!view) {
        return;
    }

    view.dispatch({ effects: clearHighlightedLineEffect.of() });
};

const handleReady = ({ view }: FlowCodeEditorReadyPayload): void => {
    editorView.value = view;
};

const focusLine = (line: number, flash = true): boolean => {
    const view = editorView.value;
    if (!view || !Number.isFinite(line)) {
        return false;
    }

    const lineNumber = Math.min(
        Math.max(Math.round(line), 1),
        view.state.doc.lines,
    );
    const lineInfo = view.state.doc.line(lineNumber);
    const effects = [];

    if (flash) {
        effects.push(highlightLineEffect.of(lineNumber));
    }

    view.dispatch({
        selection: EditorSelection.cursor(lineInfo.from),
        effects,
    });

    const lineBlock = view.lineBlockAt(lineInfo.from);
    const scrollViewportHeight = view.scrollDOM.clientHeight;
    const maxScrollTop = Math.max(
        view.scrollDOM.scrollHeight - scrollViewportHeight,
        0,
    );
    const targetScrollTop = Math.min(
        Math.max(
            lineBlock.top + lineBlock.height / 2 - scrollViewportHeight / 2,
            0,
        ),
        maxScrollTop,
    );

    view.scrollDOM.scrollTo({
        top: targetScrollTop,
        behavior: 'smooth',
    });

    try {
        view.contentDOM.focus({ preventScroll: true });
    } catch {
        view.focus();
    }

    clearFlashResetTimer();

    if (flash) {
        flashResetTimer = setTimeout(() => {
            clearHighlightedLine();
            flashResetTimer = null;
        }, 1600);
    } else {
        clearHighlightedLine();
    }

    return true;
};

const codeEditorExtensions = computed(() => {
    return createFlowCodeEditorExtensions({
        language: props.language,
        bottomPadding: props.bottomPadding,
        lineWrapping: props.lineWrapping,
        isDarkTheme: isDarkThemeClass.value,
        extraExtensions: [highlightedLineField, highlightedLineTheme],
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

defineExpose<FlowCodeEditorExpose>({
    focusLine,
});

onBeforeUnmount(() => {
    clearFlashResetTimer();
    editorView.value = null;
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
        @ready="handleReady"
    />
</template>
