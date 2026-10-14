import { ref } from 'vue';

const KEY = 'theme';

function stored() {
    try {
        return localStorage.getItem(KEY);
    } catch {
        return null; // navegação privada / armazenamento bloqueado
    }
}

/** Sem escolha salva, segue a preferência do sistema operacional. */
const initial = stored() ?? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
const theme = ref(initial);

function apply(value) {
    document.documentElement.classList.toggle('dark', value === 'dark');
}
apply(theme.value);

function toggle() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    apply(theme.value);
    try {
        localStorage.setItem(KEY, theme.value);
    } catch {
        // sem persistência: vale só para esta visita
    }
}

/** Lê um token de cor do CSS (usado pelos gráficos, que desenham em canvas). */
export const cssColor = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

export function useTheme() {
    return { theme, toggle };
}
