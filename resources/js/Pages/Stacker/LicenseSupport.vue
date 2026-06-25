<script setup lang="ts">
defineProps<{
  whatsappUrl: string | null;
  supportPhone: string | null;
}>();
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-slate-950 text-white p-6">
    <div class="max-w-lg w-full rounded-2xl border border-white/10 bg-slate-900/80 p-8 shadow-xl">
      <p class="text-sm uppercase tracking-widest text-emerald-400 mb-2">Stacker Gateway</p>
      <h1 class="text-2xl font-semibold mb-3">Licença não ativa</h1>
      <p class="text-slate-300 mb-6 leading-relaxed">
        Não foi possível validar a licença desta instalação. Entre em contato com o suporte Stacker
        para vincular seu domínio e token do agente.
      </p>
      <a
        v-if="whatsappUrl"
        :href="whatsappUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center justify-center rounded-lg bg-emerald-500 px-5 py-3 font-medium text-slate-950 hover:bg-emerald-400 transition"
      >
        Falar com suporte no WhatsApp
      </a>
      <p v-else class="text-sm text-slate-400">
        Configure STACKER_SUPPORT_WHATSAPP no ambiente ou contate seu administrador.
      </p>
      <p v-if="supportPhone" class="mt-4 text-xs text-slate-500">Suporte: {{ supportPhone }}</p>
    </div>
  </div>
</template>
