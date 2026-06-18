<script setup>
import { Link } from '@inertiajs/vue3';
import { panelNavPrefetch } from '@/composables/useAppSidebarNav';
import PwaInstallButton from '@/components/layout/PwaInstallButton.vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    showText: { type: Boolean, default: true },
    isActive: { type: Function, required: true },
    /** default | aurora | kawaii */
    variant: { type: String, default: 'default' },
    isMobile: { type: Boolean, default: false },
});

const emit = defineEmits(['item-mouseenter', 'item-mousemove', 'item-mouseleave']);

function onMouseEnter(e, label) {
    emit('item-mouseenter', e, label);
}

function onMouseMove(e) {
    emit('item-mousemove', e);
}

function onMouseLeave() {
    emit('item-mouseleave');
}

function linkClasses(href, isChild = false) {
    const active = props.isActive(href);
    if (props.variant === 'aurora') {
        return [
            'aurora-nav-link flex items-center gap-3 rounded-lg text-[13px] font-medium transition-colors',
            isChild ? 'py-2 pl-9 pr-3' : 'px-3 py-2.5',
            active ? 'aurora-nav-active' : '',
        ];
    }
    if (props.variant === 'kawaii') {
        return [
            'kawaii-nav-link flex items-center gap-3 text-[13px] font-semibold transition-colors',
            isChild ? 'py-2 pl-9 pr-3' : 'px-3 py-2.5',
            active ? 'kawaii-nav-active' : '',
        ];
    }
    return [
        'menu-item group relative',
        isChild ? 'py-2 pl-9' : '',
        props.showText ? 'justify-start' : 'lg:justify-center',
        active ? 'menu-item-active' : 'menu-item-inactive',
    ];
}

function iconClasses(href) {
    const active = props.isActive(href);
    if (props.variant === 'aurora') {
        return ['aurora-nav-icon h-[17px] w-[17px] shrink-0'];
    }
    if (props.variant === 'kawaii') {
        return ['kawaii-nav-icon h-[17px] w-[17px] shrink-0'];
    }
    return [active ? 'menu-item-icon-active' : 'menu-item-icon-inactive', 'shrink-0'];
}
</script>

<template>
    <ul
        :class="[
            'flex flex-col overflow-visible',
            variant === 'default' ? 'gap-1' : variant === 'kawaii' ? 'gap-0.5' : 'gap-1',
        ]"
    >
        <template v-for="(item, index) in items" :key="item.separator ? `sep-${index}` : item.pwaInstall ? `pwa-${index}` : (item.href ?? index)">
            <li v-if="item.separator" class="py-1" aria-hidden="true">
                <hr
                    :class="[
                        'border-t',
                        variant === 'default'
                            ? 'border-zinc-200 dark:border-zinc-700'
                            : variant === 'aurora'
                              ? 'border-[var(--aurora-border)]'
                              : 'border-[var(--kawaii-border)]',
                    ]"
                />
            </li>
            <li v-else-if="item.pwaInstall && isMobile && showText" class="pt-1.5 lg:hidden">
                <PwaInstallButton variant="banner" :theme="variant" />
            </li>
            <li v-else-if="!item.pwaInstall" class="overflow-visible">
                <Link
                    :href="item.href"
                    :prefetch="panelNavPrefetch"
                    :title="showText ? '' : item.name"
                    :class="linkClasses(item.href)"
                    @mouseenter="(e) => onMouseEnter(e, item.name)"
                    @mousemove="onMouseMove"
                    @mouseleave="onMouseLeave"
                >
                    <span :class="iconClasses(item.href)">
                        <component :is="item.icon" class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <span v-if="showText" class="flex min-w-0 flex-1 items-center gap-2 truncate">
                        <span class="truncate">{{ item.name }}</span>
                        <span
                            v-if="item.badge === 'pix-in-out'"
                            class="inline-flex shrink-0 flex-wrap items-center gap-0.5"
                        >
                            <span
                                class="rounded border border-emerald-200 bg-emerald-50 px-1 py-px text-[9px] font-semibold uppercase tracking-wide text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
                            >
                                in
                            </span>
                            <span
                                class="rounded border border-sky-200 bg-sky-50 px-1 py-px text-[9px] font-semibold uppercase tracking-wide text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200"
                            >
                                out
                            </span>
                        </span>
                    </span>
                    <span v-else class="hidden">{{ item.name }}</span>
                </Link>
                <ul
                    v-if="showText && item.children?.length"
                    class="mt-0.5 flex flex-col gap-0.5"
                >
                    <li v-for="child in item.children" :key="child.href">
                        <Link
                            :href="child.href"
                            :prefetch="panelNavPrefetch"
                            :class="linkClasses(child.href, true)"
                            @mouseenter="(e) => onMouseEnter(e, child.name)"
                            @mousemove="onMouseMove"
                            @mouseleave="onMouseLeave"
                        >
                            <span :class="iconClasses(child.href)">
                                <component :is="child.icon" class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <span class="truncate text-[13px]">{{ child.name }}</span>
                        </Link>
                    </li>
                </ul>
            </li>
        </template>
    </ul>
</template>
