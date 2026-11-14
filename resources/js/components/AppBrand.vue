<script setup lang="ts">
defineProps<{ compact?: boolean }>();
</script>

<template>
    <span class="app-brand" :class="{ compact }" role="img" aria-label="chAIt">
        <span class="sr-only">chAIt</span>
        <img v-if="compact" :src="'/brand/symbol.png'" alt="" />
        <template v-else>
            <img :src="'/brand/logo.png'" alt="" />
            <img
                class="dark-lettering dark-lettering-start"
                :src="'/brand/logo.png'"
                alt=""
                aria-hidden="true"
            />
            <img
                class="dark-lettering dark-lettering-end"
                :src="'/brand/logo.png'"
                alt=""
                aria-hidden="true"
            />
        </template>
    </span>
</template>

<style scoped>
.app-brand {
    position: relative;
    display: inline-block;
    flex: none;
    width: 180px;
    max-width: 100%;
    line-height: 0;
}
.app-brand img {
    display: block;
    width: 100%;
    height: auto;
}
.app-brand.compact {
    width: 44px;
}
.dark-lettering {
    display: none !important;
    position: absolute;
    inset: 0;
    filter: brightness(0) invert(1);
}
:global(html.dark .app-brand .dark-lettering) {
    display: block !important;
}
.dark-lettering-start {
    clip-path: inset(0 38% 0 36%);
}
.dark-lettering-end {
    clip-path: inset(0 0 0 84%);
}
</style>
