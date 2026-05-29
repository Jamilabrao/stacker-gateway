import { ref, onMounted, onBeforeUnmount } from 'vue';

export function useThemeMode() {
    const isDark = ref(false);

    let observer = null;

    onMounted(() => {
        const sync = () => {
            isDark.value = document.documentElement.classList.contains('dark');
        };
        sync();
        observer = new MutationObserver(sync);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    });

    onBeforeUnmount(() => {
        observer?.disconnect();
        observer = null;
    });

    return { isDark };
}
