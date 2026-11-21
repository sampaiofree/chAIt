<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="card-content"
    :class="cn('px-[var(--card-padding)]', props.class)"
  >
    <slot />
  </div>
</template>
