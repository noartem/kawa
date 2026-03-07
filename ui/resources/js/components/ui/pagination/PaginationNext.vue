<script setup lang="ts">
import { cn } from '@/lib/utils'
import { ChevronRight } from 'lucide-vue-next'
import type { PrimitiveProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import PaginationLink from './PaginationLink.vue'

const props = withDefaults(
  defineProps<
    PrimitiveProps & {
      class?: HTMLAttributes['class']
    }
  >(),
  {
    as: 'a',
  },
)
</script>

<template>
  <PaginationLink
    aria-label="Go to next page"
    :as="props.as"
    :as-child="props.asChild"
    :class="cn('gap-1 pr-2.5', props.class)"
  >
    <slot>Next</slot>
    <ChevronRight class="size-4" />
  </PaginationLink>
</template>
