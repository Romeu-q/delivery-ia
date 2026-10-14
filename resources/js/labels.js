import { formatCurrency, formatNumber, formatPct } from './format';

/** Nomes em português dos campos devolvidos pelas ferramentas. */
const LABELS = {
    metrics: 'Indicadores',
    revenue: 'Faturamento',
    orders: 'Pedidos',
    avg_ticket: 'Ticket médio',
    items_sold: 'Itens vendidos',
    discounts: 'Descontos',
    delivery_fees: 'Taxas de entrega',
    unique_customers: 'Clientes únicos',
    new_customers: 'Clientes novos',
    returning_customers: 'Clientes recorrentes',
    canceled_orders: 'Pedidos cancelados',
    cancel_rate_pct: 'Taxa de cancelamento (%)',
    avg_orders_per_day: 'Pedidos por dia',
    current: 'Atual',
    previous: 'Anterior',
    change: 'Variação',
    change_pct: 'Variação %',
    series: 'Série',
    by_weekday: 'Por dia da semana',
    by_hour: 'Por horário',
    products: 'Produtos',
    categories: 'Categorias',
    customers: 'Clientes',
    anomalies: 'Dias fora do padrão',
    product: 'Produto',
    category: 'Categoria',
    customer: 'Cliente',
    phone: 'Telefone',
    period: 'Período',
    weekday: 'Dia',
    hour: 'Hora',
    quantity: 'Unidades',
    quantity_current: 'Unid. atual',
    quantity_previous: 'Unid. anterior',
    quantity_change: 'Variação unid.',
    quantity_change_pct: 'Variação unid. %',
    revenue_current: 'Fat. atual',
    revenue_previous: 'Fat. anterior',
    revenue_change: 'Variação fat.',
    revenue_change_pct: 'Variação fat. %',
    share_of_revenue_pct: 'Participação %',
    avg_revenue_per_day: 'Fat. médio/dia',
    last_order: 'Último pedido',
    days_without_order: 'Dias sem pedir',
    total_customers: 'Total de clientes',
    min_days_without_order: 'Mínimo de dias sem pedir',
    max_days_without_order: 'Máximo de dias sem pedir',
    their_historical_revenue: 'Faturamento histórico deles',
    their_historical_orders: 'Pedidos históricos deles',
    top_customers_by_value: 'Clientes de maior valor',
    customers_in_previous_period: 'Clientes no período anterior',
    customers_not_returned: 'Não voltaram',
    not_returned_pct: 'Não voltaram (%)',
    revenue_from_them_in_previous_period: 'Faturamento deles no período anterior',
    top_customers_not_returned: 'Principais clientes que não voltaram',
    orders_previous_period: 'Pedidos (anterior)',
    revenue_previous_period: 'Fat. (anterior)',
    date: 'Data',
    expected_revenue: 'Faturamento esperado',
    deviation_pct: 'Desvio %',
    type: 'Tipo',
    days_analyzed: 'Dias analisados',
    granularity: 'Agrupamento',
    direction: 'Direção',
    sorted_by: 'Ordenado por',
    audience_count: 'Clientes no público',
    audience_rule: 'Regra do público',
    name: 'Nome',
    status: 'Status',
    message_title: 'Título',
    message_body: 'Mensagem',
    error: 'Erro',
};

const MONEY = /(revenue|ticket|discount|fee|price)/;

export const label = (key) => LABELS[key] ?? key.replaceAll('_', ' ');

/**
 * Formata um valor pelo nome do campo. `rowKey` é usado em tabelas de indicadores,
 * onde a coluna é "current/previous/change" e a unidade depende da linha (ex.: revenue → R$).
 */
export function formatField(key, value, rowKey = '') {
    if (value === null || value === undefined) return '—';
    if (typeof value !== 'number') return String(value);
    // Variações percentuais levam sinal (+/−); demais percentuais, não
    if (/(change|deviation)_pct$/.test(key)) return formatPct(value);
    if (key.endsWith('pct') || rowKey.endsWith('pct')) return `${formatNumber(value)}%`;
    if (MONEY.test(key) || MONEY.test(rowKey)) return formatCurrency(value);
    return formatNumber(value);
}
