<script setup lang="ts">
import { cn } from '@/lib/utils'
import { ChevronLeft } from 'lucide-vue-next'
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
    aria-label="Go to previous page"
    :as="props.as"
    :as-child="props.asChild"
    :class="cn('gap-1 pl-2.5', props.class)"
  >
    <ChevronLeft class="size-4" />
    <slot>Previous</slot>
  </PaginationLink>
</template>
