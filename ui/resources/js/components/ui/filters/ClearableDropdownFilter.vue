<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { ChevronDown, X } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';

type FilterOption = {
    value: string;
    label: string;
};

const props = withDefaults(
    defineProps<{
        modelValue: string;
        options: FilterOption[];
        label: string;
        defaultValue?: string;
        clearable?: boolean;
        clearLabel?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        defaultValue: 'all',
        clearable: false,
        clearLabel: 'Clear',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const selectedLabel = computed<string>(() => {
    return (
        props.options.find((option) => option.value === props.modelValue)
            ?.label ?? props.label
    );
});

const canClear = computed<boolean>(() => {
    return props.clearable && props.modelValue !== props.defaultValue;
});

const clearValue = (event: Event): void => {
    event.stopPropagation();
    event.preventDefault();

    if (!canClear.value) {
        return;
    }

    emit('update:modelValue', props.defaultValue);
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger :as-child="true">
            <Button
                variant="outline"
                size="default"
                :class="cn('h-9 w-full justify-between', props.class)"
            >
                <span class="truncate">{{ selectedLabel }}</span>
                <span class="ml-2 inline-flex items-center gap-1.5">
                    <span
                        v-if="canClear"
                        role="button"
                        :aria-label="props.clearLabel"
                        class="inline-flex size-5 items-center justify-center rounded-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        tabindex="0"
                        @click="clearValue"
                        @keydown.enter="clearValue"
                        @keydown.space="clearValue"
                    >
                        <X class="size-3" />
                    </span>
                    <ChevronDown class="size-4" />
                </span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel>
                {{ props.label }}
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuRadioGroup
                :model-value="props.modelValue"
                @update:model-value="(value) => emit('update:modelValue', value)"
            >
                <DropdownMenuRadioItem
                    v-for="option in props.options"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
