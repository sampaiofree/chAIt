<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'bg-card text-card-foreground flex flex-col min-w-0 gap-4 rounded-xl border py-[var(--card-padding)]',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
