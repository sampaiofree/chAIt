<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
defineOptions({ inheritAttrs: false });
defineProps<{ label: string }>();
</script>
<template>
    <Tooltip
        ><TooltipTrigger as-child
            ><AppButton
                v-bind="$attrs"
                variant="ghost"
                size="icon"
                :aria-label="label"
                :title="label"
                ><slot /></AppButton></TooltipTrigger
        ><TooltipContent>{{ label }}</TooltipContent></Tooltip
    >
</template>
