<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { Primitive, type PrimitiveProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

const props = withDefaults(
  defineProps<
    PrimitiveProps & {
      class?: HTMLAttributes['class']
      isActive?: boolean
    }
  >(),
  {
    as: 'a',
    isActive: false,
  },
)
</script>

<template>
  <Primitive
    data-slot="pagination-link"
    :as="props.as"
    :as-child="props.asChild"
    :class="cn(buttonVariants({ variant: isActive ? 'outline' : 'ghost', size: 'sm' }), props.class)"
  >
    <slot />
  </Primitive>
</template>
