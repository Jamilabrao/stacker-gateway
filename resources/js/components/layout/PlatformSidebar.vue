<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    Users,
    UserCircle2,
    Shield,
    PanelRightOpen,
    X,
    Settings,
    Mail,
    Smartphone,
    Wallet,
    ArrowLeftRight,
    AlertTriangle,
    Banknote,
    CircleDollarSign,
    Trophy,
    BadgeCheck,
    Package,
    Puzzle,
    Plug,
} from 'lucide-vue-next';
import { useSidebar } from '@/composables/useSidebar';

const page = usePage();
const { isExpanded, isMobileOpen, toggleSidebar, isMobile } = useSidebar();

const showText = () => isExpanded.value || isMobileOpen.value;

const appSettings = () => page.props.appSettings ?? {};
const appName = () => appSettings().app_name || 'Getfy';
const hasLogoFull = () => !!(appSettings().app_logo || appSettings().app_logo_dark);
const hasLogoIcon = () => !!(appSettings().app_logo_icon || appSettings().app_logo_icon_dark);

/** Classes da imagem conforme Configurações → Personalização (mesma origem do painel vendedor). */
const headerLogoImgClass = computed(() => {
    const expanded = isExpanded.value || isMobileOpen.value;
    return expanded
        ? 'h-10 max-w-[200px] object-contain object-left'
        : 'h-8 max-w-[56px] object-contain object-center';
});

const headerIconImgClass = computed(() => {
    const expanded = isExpanded.value || isMobileOpen.value;
    return expanded ? 'h-9 w-9 shrink-0 object-contain' : 'h-8 w-8 shrink-0 object-contain';
});

const iconMap = {
    Puzzle,
    Plug,
};

const navItemsCore = [
    { name: 'Dashboard', href: '/plataforma/dashboard', icon: LayoutDashboard },
    { name: 'Infoprodutores', href: '/plataforma/usuarios', icon: Users },
    { name: 'Clientes', href: '/plataforma/clientes', icon: UserCircle2 },
    { name: 'Transações', href: '/plataforma/transacoes', icon: ArrowLeftRight },
    { name: 'Disputas MED', href: '/plataforma/disputas', icon: AlertTriangle },
    { name: 'Produtos', href: '/plataforma/produtos', icon: Package },
    { name: 'Verificações KYC', href: '/plataforma/verificacoes-kyc', icon: BadgeCheck },
    { name: 'Saques', href: '/plataforma/saques', icon: Banknote },
    { name: 'Saldo', href: '/plataforma/saldo', icon: CircleDollarSign },
    { name: 'Financeiro', href: '/plataforma/financeiro', icon: Wallet },
    { name: 'Configurações', href: '/plataforma/configuracoes', icon: Settings },
    { name: 'Plugins', href: '/plataforma/gerenciar-plugins', icon: Puzzle },
    { name: 'App', href: '/plataforma/app', icon: Smartphone },
    { name: 'Conquistas', href: '/plataforma/conquistas', icon: Trophy },
    { name: 'E-mail Marketing', href: '/plataforma/email-marketing', icon: Mail },
];

const pluginNavItems = computed(() => {
    const raw = page.props.pluginNavItems ?? [];
    return raw.map((item) => ({
        name: item.name,
        href: item.href,
        icon: item.icon && iconMap[item.icon] ? iconMap[item.icon] : Puzzle,
    }));
});

const navItems = computed(() => [...navItemsCore, ...pluginNavItems.value]);

function isNavItemActive(href) {
    if (href.includes('?')) {
        const url = page.url.split('#')[0];

        return url === href;
    }

    return isActive(href);
}

function isActive(href) {
    const url = page.url.split('?')[0];
    if (href === '/plataforma/dashboard') {
        return url === '/plataforma/dashboard';
    }
    if (href === '/plataforma/transacoes') {
        return url === '/plataforma/transacoes' || url.startsWith('/plataforma/transacoes/');
    }
    if (href === '/plataforma/disputas') {
        return url === '/plataforma/disputas' || url.startsWith('/plataforma/disputas/');
    }
    if (href === '/plataforma/clientes') {
        return url === '/plataforma/clientes' || url.startsWith('/plataforma/clientes/');
    }
    if (href === '/plataforma/produtos') {
        return url === '/plataforma/produtos' || url.startsWith('/plataforma/produtos/');
    }
    if (href === '/plataforma/verificacoes-kyc') {
        return url === '/plataforma/verificacoes-kyc' || url.startsWith('/plataforma/verificacoes-kyc/');
    }
    if (href === '/plataforma/saques') {
        return url === '/plataforma/saques' || url.startsWith('/plataforma/saques/');
    }
    if (href === '/plataforma/saldo') {
        return url === '/plataforma/saldo' || url.startsWith('/plataforma/saldo/');
    }
    if (href === '/plataforma/usuarios') {
        return url === '/plataforma/usuarios' || (url.startsWith('/plataforma/usuarios/') && !url.startsWith('/plataforma/usuarios/create'));
    }
    if (href === '/plataforma/financeiro') {
        return url === '/plataforma/financeiro' || url.startsWith('/plataforma/financeiro/');
    }
    if (href === '/plataforma/configuracoes') {
        return url === '/plataforma/configuracoes' || url.startsWith('/plataforma/configuracoes/');
    }
    return url === href || url.startsWith(`${href}/`);
}

const linkActive =
    'bg-[var(--color-primary)]/15 text-zinc-900 dark:text-white';
/** Mesma base do AppSidebar (fundo zinc-100): hover visível em claro */
const linkInactive =
    'text-zinc-600 hover:bg-zinc-200/70 dark:text-zinc-400 dark:hover:bg-zinc-800/80';
</script>

<template>
    <aside
        class="fixed inset-y-0 left-0 z-[99999] flex h-screen w-[260px] flex-col rounded-r-2xl border-r border-zinc-200/60 bg-zinc-100 transition-all duration-300 ease-in-out dark:border-zinc-700/60 dark:bg-zinc-900"
        :class="[
            {
                'translate-x-0 shadow-xl': isMobileOpen,
                '-translate-x-full': !isMobileOpen,
                'lg:translate-x-0': true,
                'lg:w-[260px]': isExpanded || isMobileOpen,
                'lg:w-[72px]': !isExpanded && !isMobileOpen,
            },
        ]"
    >
        <div class="flex h-14 shrink-0 items-center justify-between gap-2 border-b border-zinc-200/60 px-3 dark:border-zinc-700/60">
            <Link
                href="/plataforma/dashboard"
                :class="[
                    'flex min-w-0 flex-1 items-center overflow-hidden rounded-lg py-1.5',
                    showText() ? 'justify-start gap-2' : 'justify-center',
                ]"
            >
                <template v-if="hasLogoFull()">
                    <img
                        v-if="appSettings().app_logo"
                        :src="appSettings().app_logo"
                        :alt="appName()"
                        :class="[headerLogoImgClass, appSettings().app_logo_dark ? 'dark:hidden' : '']"
                    />
                    <img
                        v-if="appSettings().app_logo_dark"
                        :src="appSettings().app_logo_dark"
                        :alt="appName()"
                        :class="['hidden dark:block', headerLogoImgClass]"
                    />
                </template>
                <template v-else-if="hasLogoIcon()">
                    <img
                        v-if="appSettings().app_logo_icon"
                        :src="appSettings().app_logo_icon"
                        :alt="appName()"
                        :class="[headerIconImgClass, appSettings().app_logo_icon_dark ? 'dark:hidden' : '']"
                    />
                    <img
                        v-if="appSettings().app_logo_icon_dark"
                        :src="appSettings().app_logo_icon_dark"
                        :alt="appName()"
                        :class="['hidden dark:block', headerIconImgClass]"
                    />
                </template>
                <Shield v-else class="h-8 w-8 shrink-0 text-[var(--color-primary)]" />
            </Link>
            <button
                v-if="isMobile"
                type="button"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-200/80 dark:hover:bg-zinc-800"
                aria-label="Fechar menu"
                @click="toggleSidebar"
            >
                <X class="h-5 w-5" />
            </button>
            <button
                v-else
                type="button"
                class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-200/80 lg:flex dark:hover:bg-zinc-800"
                :title="isExpanded ? 'Recolher' : 'Expandir'"
                aria-label="Alternar largura do menu"
                @click="toggleSidebar"
            >
                <PanelRightOpen class="h-5 w-5" />
            </button>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto p-2">
            <Link
                v-for="item in navItems"
                :key="item.href"
                :href="item.href"
                class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors"
                :class="isNavItemActive(item.href) ? linkActive : linkInactive"
                @click="isMobile ? toggleSidebar() : null"
            >
                <component :is="item.icon" class="h-5 w-5 shrink-0" />
                <span v-show="showText()">{{ item.name }}</span>
            </Link>
        </nav>

        <div v-show="showText()" class="border-t border-zinc-200/60 p-3 text-xs text-zinc-500 dark:border-zinc-700/60 dark:text-zinc-500">
            Operador do gateway
        </div>
    </aside>
</template>
