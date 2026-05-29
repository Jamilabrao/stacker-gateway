import { ref } from 'vue';

const heading = ref({ title: '', subtitle: '' });

export function useThemedPageHeading() {
    function setHeading({ title = '', subtitle = '' } = {}) {
        heading.value = { title, subtitle };
    }

    function clearHeading() {
        heading.value = { title: '', subtitle: '' };
    }

    return {
        heading,
        setHeading,
        clearHeading,
    };
}
