<script setup>
import { Link } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { useSidebar } from '@/composables/useSidebar';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import PwaInstallButton from '@/components/layout/PwaInstallButton.vue';
import { useAppSidebarNav, panelNavPrefetch } from '@/composables/useAppSidebarNav';

const { isMobileOpen, toggleSidebar, isMobile } = useSidebar();
const {
    page,
    homeHref,
    appSettings,
    appName,
    hasLogoFull,
    navItems,
    isActive,
} = useAppSidebarNav();
</script>

<template>
    <aside
        :class="[
            'aurora-sidebar fixed left-0 top-0 z-[99999] flex h-screen flex-col',
            'transition-transform duration-300 ease-in-out',
            isMobileOpen ? 'translate-x-0' : '-translate-x-full',
            'lg:translate-x-0',
        ]"
    >
        <div class="flex h-[72px] shrink-0 items-center justify-between px-5">
            <Link
                :href="homeHref"
                :prefetch="panelNavPrefetch"
                class="flex min-w-0 flex-1 items-center gap-3 overflow-hidden"
            >
                <template v-if="hasLogoFull()">
                    <img
                        v-if="appSettings().app_logo"
                        :src="appSettings().app_logo"
                        :alt="appName()"
                        class="h-8 max-w-[170px] object-contain object-left dark:hidden"
                    />
                    <img
                        v-if="appSettings().app_logo_dark"
                        :src="appSettings().app_logo_dark"
                        :alt="appName()"
                        class="hidden h-8 max-w-[170px] object-contain object-left dark:block"
                    />
                    <img
                        v-else-if="appSettings().app_logo && !appSettings().app_logo_dark"
                        :src="appSettings().app_logo"
                        :alt="appName()"
                        class="hidden h-8 max-w-[170px] object-contain object-left dark:block"
                    />
                </template>
                <span v-else class="aurora-fg truncate text-[15px] font-semibold tracking-tight">
                    {{ appName() }}
                </span>
            </Link>
            <button
                v-if="isMobile"
                type="button"
                class="aurora-icon-btn ml-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-md"
                aria-label="Fechar menu"
                @click="toggleSidebar"
            >
                <X class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto no-scrollbar px-3 py-4">
            <ul class="flex flex-col gap-1">
                <template v-for="(item, index) in navItems" :key="item.separator ? `sep-${index}` : (item.href ?? index)">
                    <li v-if="item.separator" class="my-1.5" aria-hidden="true" />
                    <li v-else>
                        <Link
                            :href="item.href"
                            :prefetch="panelNavPrefetch"
                            :class="[
                                'aurora-nav-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-[13px] font-medium transition-colors',
                                isActive(item.href) ? 'aurora-nav-active' : '',
                            ]"
                        >
                            <component
                                :is="item.icon"
                                class="aurora-nav-icon h-[17px] w-[17px] shrink-0"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ item.name }}</span>
                        </Link>
                    </li>
                </template>
            </ul>
        </nav>

        <div class="aurora-sidebar-footer shrink-0 space-y-3 px-4 py-4">
            <ConquistasWidget v-if="!page.props.customer_panel" variant="sidebar" />
            <div v-if="isMobile" class="space-y-2 lg:hidden">
                <PwaInstallButton />
            </div>
        </div>
    </aside>
</template>
