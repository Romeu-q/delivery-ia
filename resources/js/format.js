const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const number = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });

export const formatCurrency = (value) => currency.format(value ?? 0);
export const formatNumber = (value) => number.format(value ?? 0);

export function formatPct(value) {
    if (value === null || value === undefined) return '—';
    return `${value > 0 ? '+' : ''}${number.format(value)}%`;
}

export const formatValue = (value, format) => (format === 'currency' ? formatCurrency(value) : formatNumber(value));
