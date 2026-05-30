<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, CircleDollarSign, Package, Cable } from 'lucide-vue-next';
import { usePwaInstall } from '@/composables/usePwaInstall';
import { panelNavPrefetch } from '@/composables/useAppSidebarNav';

const DEFAULT_LOGO = 'https://cdn.getfy.cloud/collapsed-logo.png';

const page = usePage();
const { isStandalone } = usePwaInstall('painel');

const appSettings = computed(() => page.props.appSettings ?? {});

const logoLight = computed(() => {
    const s = appSettings.value;
    return s.pwa_nav_logo || s.app_logo_icon || DEFAULT_LOGO;
});

const logoDark = computed(() => {
    const s = appSettings.value;
    return s.pwa_nav_logo_dark || s.app_logo_icon_dark || logoLight.value;
});

const showDualLogo = computed(() => {
    const s = appSettings.value;
    return !!(s.pwa_nav_logo_dark || s.app_logo_icon_dark);
});

const navItems = [
    { name: 'Home', href: '/dashboard', icon: LayoutDashboard },
    { name: 'Vendas', href: '/vendas', icon: CircleDollarSign },
    { name: 'Produtos', href: '/produtos', icon: Package },
    { name: 'Integrações', href: '/integracoes', icon: Cable },
];

const navVisible = ref(true);
const lastScrollY = ref(0);
const SCROLL_THRESHOLD = 20;
const TOP_THRESHOLD = 80;

function isActive(href) {
    const url = page.url;
    if (href === '/dashboard') return url === '/dashboard' || url === '/';
    return url === href || url.startsWith(href + '/');
}

function navLinkClass(href) {
    return [
        'flex min-w-0 flex-col items-center justify-center gap-0.5 px-1 py-2 text-[11px] font-medium leading-tight rounded-xl transition-colors cursor-pointer touch-manipulation border-0 bg-transparent text-center no-underline',
        isActive(href)
            ? 'text-[var(--color-primary)]'
            : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200',
    ];
}

function onScroll() {
    if (typeof window === 'undefined') return;
    const y = window.scrollY ?? window.pageYOffset;
    if (y <= TOP_THRESHOLD) {
        navVisible.value = true;
    } else if (y > lastScrollY.value && y - lastScrollY.value > SCROLL_THRESHOLD) {
        navVisible.value = false;
        lastScrollY.value = y;
    } else if (y < lastScrollY.value && lastScrollY.value - y > SCROLL_THRESHOLD) {
        navVisible.value = true;
        lastScrollY.value = y;
    }
    lastScrollY.value = y;
}

onMounted(() => {
    lastScrollY.value = typeof window !== 'undefined' ? (window.scrollY ?? window.pageYOffset) : 0;
    window.addEventListener('scroll', onScroll, { passive: true });
});

onUnmounted(() => {
    if (typeof window !== 'undefined') window.removeEventListener('scroll', onScroll);
});
</script>

<template>
    <nav
        v-if="isStandalone"
        class="fixed bottom-4 left-4 right-4 z-[99998] mx-auto grid max-w-md grid-cols-5 items-end gap-0 rounded-2xl border border-zinc-200 bg-white px-1 pb-2 pt-3 shadow-lg dark:border-zinc-800 dark:bg-zinc-900 lg:hidden transition-transform duration-300 ease-out"
        aria-label="Navegação principal"
        role="navigation"
        :style="{ transform: navVisible ? 'translateY(0)' : 'translateY(calc(100% + 2rem))' }"
    >
        <Link
            v-for="item in navItems.slice(0, 2)"
            :key="item.href"
            :href="item.href"
            :prefetch="panelNavPrefetch"
            :aria-current="isActive(item.href) ? 'page' : undefined"
            :aria-label="item.name"
            :class="navLinkClass(item.href)"
        >
            <component :is="item.icon" class="h-5 w-5 shrink-0" aria-hidden="true" />
            <span class="truncate">{{ item.name }}</span>
        </Link>

        <div class="relative flex min-h-12 items-end justify-center">
            <Link
                href="/dashboard"
                :prefetch="panelNavPrefetch"
                aria-label="Home"
                class="absolute bottom-1 left-1/2 z-10 flex -translate-x-1/2 cursor-pointer touch-manipulation items-center justify-center border-0 bg-transparent p-0 no-underline"
            >
                <span
                    class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-white p-2.5 shadow-lg shadow-zinc-300/80 ring-2 ring-zinc-200 dark:bg-zinc-600 dark:shadow-black/50 dark:ring-zinc-400/40"
                >
                    <img
                        :src="logoLight"
                        alt=""
                        class="block h-full w-full max-h-9 max-w-9 object-contain object-center"
                        :class="showDualLogo ? 'dark:hidden' : ''"
                        aria-hidden="true"
                    />
                    <img
                        v-if="showDualLogo"
                        :src="logoDark"
                        alt=""
                        class="hidden h-full w-full max-h-9 max-w-9 object-contain object-center dark:block"
                        aria-hidden="true"
                    />
                </span>
            </Link>
        </div>

        <Link
            v-for="item in navItems.slice(2)"
            :key="item.href"
            :href="item.href"
            :prefetch="panelNavPrefetch"
            :aria-current="isActive(item.href) ? 'page' : undefined"
            :aria-label="item.name"
            :class="navLinkClass(item.href)"
        >
            <component :is="item.icon" class="h-5 w-5 shrink-0" aria-hidden="true" />
            <span class="truncate">{{ item.name }}</span>
        </Link>
    </nav>
</template>
