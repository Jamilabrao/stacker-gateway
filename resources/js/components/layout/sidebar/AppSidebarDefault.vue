<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PanelRightOpen, X } from 'lucide-vue-next';
import { useSidebar } from '@/composables/useSidebar';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import PwaInstallButton from '@/components/layout/PwaInstallButton.vue';
import { useAppSidebarNav, panelNavPrefetch } from '@/composables/useAppSidebarNav';

const { isExpanded, isMobileOpen, toggleSidebar, isMobile } = useSidebar();
const {
    page,
    homeHref,
    appSettings,
    appName,
    hasLogoFull,
    hasLogoIcon,
    navItems,
    isActive,
} = useAppSidebarNav();

const showText = () => isExpanded.value || isMobileOpen.value;

const hoverLabel = ref('');
const hoverX = ref(0);
const hoverY = ref(0);

function onItemMouseEnter(event, label) {
    if (showText() || isMobile.value) return;
    hoverLabel.value = label;
    hoverX.value = event.clientX + 14;
    hoverY.value = event.clientY;
}

function onItemMouseMove(event) {
    if (!hoverLabel.value) return;
    hoverX.value = event.clientX + 14;
    hoverY.value = event.clientY;
}

function onItemMouseLeave() {
    hoverLabel.value = '';
}
</script>

<template>
    <aside
        :class="[
            'fixed left-0 top-0 z-[99999] flex h-screen flex-col rounded-r-2xl bg-zinc-100 transition-all duration-300 ease-in-out dark:bg-zinc-900',
            {
                'w-[260px] translate-x-0': isMobileOpen,
                '-translate-x-full': !isMobileOpen,
                'lg:translate-x-0': true,
                'lg:w-[260px]': isExpanded || isMobileOpen,
                'lg:w-[72px]': !isExpanded && !isMobileOpen,
            },
        ]"
    >
        <div
            :class="[
                'flex items-center border-b border-zinc-200/60 px-4 py-5 dark:border-zinc-700/60',
                showText() ? 'justify-between gap-2' : 'lg:justify-center',
            ]"
        >
            <template v-if="showText()">
                <Link
                    :href="homeHref"
                    :prefetch="panelNavPrefetch"
                    class="flex min-w-0 flex-1 items-center gap-2 overflow-hidden text-zinc-900 dark:text-white"
                >
                    <template v-if="hasLogoFull()">
                        <img v-if="appSettings().app_logo" :src="appSettings().app_logo" :alt="appName()" class="h-10 max-w-[200px] object-contain object-left" :class="appSettings().app_logo_dark ? 'dark:hidden' : ''" />
                        <img v-if="appSettings().app_logo_dark" :src="appSettings().app_logo_dark" :alt="appName()" class="hidden h-10 max-w-[200px] object-contain object-left dark:block" />
                    </template>
                    <span v-else class="truncate text-lg font-semibold">{{ appName() }}</span>
                </Link>
                <button
                    type="button"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    :aria-label="isMobile ? 'Fechar menu' : 'Recolher menu'"
                    @click="toggleSidebar"
                >
                    <X v-if="isMobile" class="h-5 w-5" aria-hidden="true" />
                    <PanelRightOpen v-else class="h-5 w-5" aria-hidden="true" />
                </button>
            </template>
            <button
                v-else
                type="button"
                class="flex h-14 w-14 items-center justify-center rounded-lg text-zinc-600 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                aria-label="Expandir menu"
                @click="toggleSidebar"
            >
                <template v-if="hasLogoIcon()">
                    <img v-if="appSettings().app_logo_icon" :src="appSettings().app_logo_icon" :alt="appName()" class="h-12 w-12 object-contain" :class="appSettings().app_logo_icon_dark ? 'dark:hidden' : ''" />
                    <img v-if="appSettings().app_logo_icon_dark" :src="appSettings().app_logo_icon_dark" :alt="appName()" class="hidden h-12 w-12 object-contain dark:block" />
                </template>
                <span v-else class="flex h-12 w-12 items-center justify-center rounded-lg bg-zinc-200 text-lg font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">
                    {{ appName().charAt(0) }}
                </span>
            </button>
        </div>
        <nav class="flex-1 overflow-y-auto overflow-x-visible no-scrollbar px-3 py-4">
            <ul class="flex flex-col gap-1 overflow-visible">
                <template v-for="(item, index) in navItems" :key="item.separator ? `sep-${index}` : (item.href ?? index)">
                    <li v-if="item.separator">
                        <hr class="my-2 border-t border-zinc-200 dark:border-zinc-700" />
                    </li>
                    <li v-else class="overflow-visible">
                        <Link
                            :href="item.href"
                            :prefetch="panelNavPrefetch"
                            :title="showText() ? '' : item.name"
                            @mouseenter="(e) => onItemMouseEnter(e, item.name)"
                            @mousemove="onItemMouseMove"
                            @mouseleave="onItemMouseLeave"
                            :class="[
                                'menu-item group relative',
                                showText() ? 'justify-start' : 'lg:justify-center',
                                isActive(item.href) ? 'menu-item-active' : 'menu-item-inactive',
                            ]"
                        >
                            <span
                                :class="[
                                    'shrink-0',
                                    isActive(item.href) ? 'menu-item-icon-active' : 'menu-item-icon-inactive',
                                ]"
                            >
                                <component :is="item.icon" class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <span v-if="showText()" class="truncate">{{ item.name }}</span>
                            <span v-else class="hidden">{{ item.name }}</span>
                        </Link>
                    </li>
                </template>
            </ul>
        </nav>
        <div v-if="isMobile && showText()" class="space-y-2 px-4 py-4 lg:hidden">
            <PwaInstallButton />
            <ConquistasWidget v-if="!page.props.customer_panel" variant="sidebar" />
        </div>
    </aside>
    <div
        v-if="hoverLabel"
        class="pointer-events-none fixed z-[100001] hidden -translate-y-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-2.5 py-1 text-xs font-medium text-white shadow-lg dark:bg-zinc-100 dark:text-zinc-900 lg:block"
        :style="{ left: `${hoverX}px`, top: `${hoverY}px` }"
    >
        {{ hoverLabel }}
    </div>
</template>
