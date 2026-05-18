import { ref } from 'vue';

/**
 * Busca endereço por CEP (ViaCEP).
 * @returns {{ fetchCep: (cep: string) => Promise<{ street: string, neighborhood: string, city: string, uf: string }|null>, loading: import('vue').Ref<boolean>, error: import('vue').Ref<string> }}
 */
export function useViaCep() {
    const loading = ref(false);
    const error = ref('');

    async function fetchCep(cep) {
        const digits = String(cep ?? '').replace(/\D/g, '').slice(0, 8);
        error.value = '';
        if (digits.length < 8) {
            return null;
        }
        loading.value = true;
        try {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 8000);
            const res = await fetch(`https://viacep.com.br/ws/${digits}/json/`, { signal: controller.signal });
            clearTimeout(timeout);
            const data = await res.json();
            if (!res.ok || data?.erro) {
                error.value = 'CEP não encontrado.';
                return null;
            }
            return {
                street: data.logradouro || '',
                neighborhood: data.bairro || '',
                city: data.localidade || '',
                uf: data.uf || '',
            };
        } catch {
            error.value = 'Não foi possível buscar o CEP.';
            return null;
        } finally {
            loading.value = false;
        }
    }

    return { fetchCep, loading, error };
}
