import { ref } from 'vue';

const expanded = ref(false);
const mobileOpen = ref(false);
let initialized = false;

export function useNavigation(initial = false) {
    if (!initialized) {
        const cookie = document.cookie
            .split('; ')
            .find((item) => item.startsWith('sidebar_state='));
        expanded.value = cookie ? cookie.split('=')[1] === 'true' : initial;
        initialized = true;
    }

    function toggle() {
        if (window.matchMedia('(max-width: 1023px)').matches) {
            mobileOpen.value = !mobileOpen.value;

            return;
        }

        expanded.value = !expanded.value;
        document.cookie = `sidebar_state=${expanded.value}; path=/; max-age=31536000; samesite=lax`;
    }

    return { expanded, mobileOpen, toggle };
}
