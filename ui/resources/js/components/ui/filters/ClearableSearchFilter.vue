<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Search, X } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        placeholder?: string;
        clearable?: boolean;
        clearLabel?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        placeholder: '',
        clearable: false,
        clearLabel: 'Clear',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    input: [];
    clear: [];
}>();

const hasValue = computed<boolean>(() => props.modelValue.trim().length > 0);

const onInput = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    emit('update:modelValue', target.value);
    emit('input');
};

const clearValue = (): void => {
    if (!hasValue.value) {
        return;
    }

    emit('update:modelValue', '');
    emit('input');
    emit('clear');
};
</script>

<template>
    <div :class="cn('relative', props.class)">
        <Search
            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <Input
            :model-value="props.modelValue"
            :placeholder="props.placeholder"
            class="h-9 pl-9"
            :class="props.clearable && hasValue ? 'pr-10' : undefined"
            @input="onInput"
        />
        <button
            v-if="props.clearable && hasValue"
            type="button"
            class="absolute top-1/2 right-2 inline-flex size-6 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            :aria-label="props.clearLabel"
            @click="clearValue"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>
