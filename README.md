# Assistente Inteligente de Performance para Delivery

Aplicação em que o gestor de um delivery conversa em linguagem natural com seus dados de vendas, produtos e
clientes. A IA (Google Gemini) é consultada **em tempo real** a cada pergunta, mas **nunca é a fonte dos
números**: ela escolhe quais consultas fazer (via *function calling*), o backend executa consultas fixas e
parametrizadas no MySQL e devolve os números já calculados, e a IA interpreta e recomenda.

- **Aplicação:** `<URL pública>`
- **Repositório:** `<URL do repositório>`

Stack: PHP 8.4 · Laravel 13 · MySQL 8 · Vue 3 (Vite + Tailwind) · Chart.js · Google Gemini · Docker (FrankenPHP) · Railway.

---

## Funcionalidades

- Chat em linguagem natural com **resposta em streaming** (o texto aparece enquanto é gerado).
- **Function calling**: 11 ferramentas de consulta + 1 ação (rascunho de campanha).
- **Comparação automática entre períodos** ("este mês" é comparado com os mesmos dias do mês anterior).
- **Gráficos gerados dinamicamente** a partir dos dados do banco retornados pelas ferramentas.
- **Verificação dos números**: cada número da resposta é conferido contra os dados do banco; a interface
  mostra "✓ N números conferidos" ou sinaliza os que não foram encontrados.
- **Ação com IA**: "crie uma campanha para clientes que não compram há mais de 30 dias" → o backend
  identifica o público, salva como rascunho e a IA escreve a mensagem.
- **Detecção de anomalias** (dias fora do padrão) e alertas automáticos no topo da tela.
- Memória da conversa, histórico, sugestões de perguntas, indicadores do mês.
- Tokens, custo (US$) e latência por resposta e consolidados do dia; logs estruturados.
- **Fallback entre modelos**, rate limit, cache dos resultados analíticos, testes automatizados.
- Estrutura preparada para **múltiplos estabelecimentos** (`store_id` em todas as tabelas de negócio).

---

## Instalação local

Pré-requisitos: PHP 8.3+ (extensões `pdo_mysql`, `mbstring`, `intl`), Composer, Node 20.19+ e MySQL 8.

```bash
git clone <URL do repositório> delivery-ia && cd delivery-ia
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
```

Edite o `.env`:

```dotenv
DB_DATABASE=delivery_ia
DB_USERNAME=root
DB_PASSWORD=
GEMINI_API_KEY=sua-chave   # gratuita em https://aistudio.google.com/apikey
```

Crie o banco e os dados fictícios (≈2.000 pedidos em 14 meses, relativos à data atual):

```bash
mysql -u root -e "CREATE DATABASE delivery_ia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate --seed
php artisan serve
```

Acesse http://localhost:8000.

Para desenvolvimento do front com hot reload: `npm run dev` em outro terminal.

### Testes

Os testes usam um banco MySQL separado (`delivery_ia_test`, configurado em `phpunit.xml`):

```bash
mysql -u root -e "CREATE DATABASE delivery_ia_test"
php artisan test
```

### Recriar a base fictícia

Os dados são gerados relativos ao dia do seed. `php artisan demo:refresh` recria tudo; no deploy, o container
executa `demo:refresh --if-stale`, que só recria se não houver pedidos nas últimas 48 h.

### Deploy (Railway)

1. Novo projeto → *Deploy from GitHub repo* (o `Dockerfile` e o `railway.json` são detectados).
2. Adicionar um serviço **MySQL** no mesmo projeto.
3. Variáveis do serviço da aplicação:
   `APP_KEY` (saída de `php artisan key:generate --show`), `APP_URL`, `DB_CONNECTION=mysql`,
   `DB_URL=${{MySQL.MYSQL_URL}}`, `GEMINI_API_KEY`, `SESSION_SECURE_COOKIE=true`.
4. *Generate Domain*. No boot, o container roda as migrations e cria a base fictícia.

---

## Arquitetura

```
Usuário → Vue (chat) ──POST /api/chat──▶ Laravel (ChatController)
                                          │
                                          ▼
                                   AssistantService ──pergunta + lista de ferramentas──▶ Gemini
                                          ▲                                               │
                                          │◀────────── functionCall(nome, argumentos) ────┘
                                          │
                       ToolRegistry → valida argumentos → SalesAnalytics → MySQL (consultas fixas)
                                          │
                                          └── functionResponse (números já calculados) ──▶ Gemini
                                                                                          │
Vue ◀── SSE: tool / delta / chart / campaign / done ◀── texto em streaming ◀──────────────┘
                                          │
                              NumberGuard confere os números · salva mensagem, tokens e custo
```

### Front-end (`resources/js`)

- `App.vue` — layout (sidebar, indicadores, chat, entrada).
- `composables/useChat.js` — estado do chat (mensagens, loading, erro, conversa atual) e consumo do stream.
- `api.js` — chamadas REST e leitura de **Server-Sent Events via `fetch`** (POST; `EventSource` só faz GET).
- `components/` — `ChatMessage` (markdown sanitizado com DOMPurify, etapas, gráficos, selo de verificação),
  `ChartBlock` (Chart.js), `CampaignCard`, `KpiBar`, `ChatInput`, `Sidebar`.

### Back-end (`app/`)

| Camada | Responsabilidade |
|---|---|
| `Http/Controllers/Api` | Controllers finos: chat (SSE), conversas, dashboard, campanhas, uso. |
| `Http/Requests/ChatRequest` | Validação e saneamento da pergunta (tamanho, caracteres de controle, UUID). |
| `Http/Middleware/IdentifyVisitor` | Visitante anônimo por sessão (isola conversas) e loja atual resolvida no servidor. |
| `AI/AssistantService` | Orquestra o loop modelo → ferramentas → modelo, streaming, fallback, custo, persistência. |
| `AI/Gemini/GeminiClient` | Cliente HTTP da API do Gemini (`streamGenerateContent` com SSE), sem SDK. |
| `AI/Tools/*` | Uma classe por ferramenta: descrição, JSON Schema, regras de validação e execução. |
| `AI/NumberGuard` | Confere cada número da resposta contra os resultados das ferramentas. |
| `AI/SystemPrompt` | Regras de comportamento, formato e segurança. |
| `Analytics/SalesAnalytics` | **Todas** as consultas SQL (query builder com bindings), sempre filtradas por loja. |
| `Analytics/PeriodResolver` | Converte períodos permitidos (`this_month`, `last_30_days`…) em datas e define o período de comparação. |

### Banco de dados

`stores`, `customers`, `products`, `orders`, `order_items`, `conversations`, `messages`, `campaigns`,
`campaign_customer` — todas criadas por migrations.

- `orders.total = subtotal − discount + delivery_fee`. **Faturamento** = soma de `total` dos pedidos não cancelados.
- `customers.first_order_at/last_order_at` são desnormalizados para consultas de recência sem varrer pedidos.
- Índices: `orders(store_id, ordered_at, status)` atende praticamente todas as consultas por período;
  `orders(customer_id, ordered_at)`, `order_items(product_id, order_id)`, `customers(store_id, last_order_at)`.
- `messages` guarda pergunta, resposta, ferramentas usadas, gráficos, verificação, modelo, tokens, custo e latência.

### Comunicação com a IA

1. A pergunta e as últimas mensagens da conversa (só texto) são enviadas com o *system prompt* e as
   declarações das ferramentas.
2. O Gemini responde com uma ou mais chamadas de função (ex.: `get_sales_summary{period: "this_month"}`).
3. O backend valida os argumentos, executa a consulta da loja atual e devolve o resultado agregado.
4. O ciclo se repete (máx. 5 rodadas; na última, as ferramentas são desligadas para forçar a resposta).
5. O texto final é enviado ao navegador em streaming, conferido pelo `NumberGuard` e salvo.

---

## Modelo de IA

**Google Gemini 2.5 Flash** (principal) com **Gemini 2.5 Flash-Lite** como fallback, configuráveis no `.env`.

- Suporte nativo a *function calling* paralelo e streaming, que são a base da arquitetura.
- Bom desempenho em português e em interpretação de dados tabulares.
- Custo baixo (US$ 0,30 / 1M tokens de entrada) e camada gratuita para a demonstração.
- Latência baixa — importante numa interface de chat.

O provedor está isolado em `GeminiClient`; trocar por outro (Claude, OpenAI) exige um cliente com a mesma
interface de streaming e a conversão do formato de ferramentas, sem tocar nas consultas.

## Estratégia: da pergunta aos dados

A IA não recebe o banco. Ela recebe um **catálogo de ferramentas** com descrições claras, e decide quais chamar:

| Pergunta | Ferramentas que a IA costuma chamar |
|---|---|
| Quanto vendi este mês? / Qual meu ticket médio? | `get_sales_summary` |
| Por que minhas vendas caíram? | `get_sales_summary` + `get_category_performance` + `get_product_trends` + `get_churned_customers` |
| Qual foi meu melhor dia da semana? | `get_sales_by_weekday` |
| Qual horário possui maior volume? | `get_sales_by_hour` |
| Qual produto mais vendeu? / Quais estão perdendo vendas? | `get_top_products` / `get_product_trends` |
| Melhores clientes / clientes deixando de comprar / sem comprar há 30 dias | `get_top_customers` / `get_churned_customers` / `get_inactive_customers` |
| Meu faturamento está crescendo? | `get_sales_timeseries` |
| Aconteceu algo fora do normal? | `detect_anomalies` |
| Crie uma campanha para inativos | `get_inactive_customers` + `create_campaign_draft` |

Cada ferramenta devolve **dados agregados e já comparados** (atual, anterior, variação absoluta e %), com
poucas linhas (limites de 5 a 15 itens). Assim a IA recebe só o necessário — tipicamente 1 a 3 KB por
ferramenta — e não precisa fazer contas.

Para adicionar uma nova capacidade (ex.: "clientes cuja frequência caiu mais de 30%"): criar o método no
`SalesAnalytics`, criar uma classe em `app/AI/Tools` e registrá-la no `AppServiceProvider`.

---

## Perguntas obrigatórias

### 1. Como você garante que a IA não invente números?

Em três camadas:

1. **Arquitetura**: a IA não tem acesso ao banco nem recebe dados brutos. Todos os números vêm das
   ferramentas, calculados em SQL/PHP — inclusive variações percentuais e diferenças, para que ela não precise
   fazer contas.
2. **Instrução**: o *system prompt* obriga a usar somente valores retornados pelas ferramentas, e a dizer que não
   tem o dado quando ele não existir. Temperatura baixa (0,2).
3. **Verificação**: o `NumberGuard` extrai todos os números da resposta (formato brasileiro) e procura cada um nos
   resultados das ferramentas (com tolerância só para arredondamento). O resultado fica salvo e aparece na
   interface; números não encontrados são destacados para o gestor. Os gráficos são montados direto dos dados
   do banco, sem passar pela IA.

### 2. Como impediria que uma pergunta maliciosa executasse comandos perigosos no banco?

A pergunta nunca vira SQL. O único caminho até o banco são as ferramentas, que:

- formam uma **lista fechada** (nome desconhecido é recusado);
- **validam os argumentos** com regras Laravel (`enum` de períodos, datas `Y-m-d`, inteiros com mínimo e máximo);
- executam **consultas fixas com bindings** (query builder/PDO), imunes a SQL Injection;
- recebem a loja do **contexto do servidor** (`ToolContext`), nunca da IA — ela não consegue consultar outra loja;
- são somente leitura, exceto `create_campaign_draft`, que só cria rascunhos, com público calculado pelo backend,
  limite por hora e liga/desliga em `config/ai.php` (`allowed_actions`).

Em produção, o usuário do banco da aplicação também deve ter apenas as permissões necessárias (sem `DROP`,
`GRANT` etc.), e as consultas analíticas poderiam usar uma conexão só-leitura numa réplica.

Contra *prompt injection*, além do prompt instruir a ignorar pedidos de mudança de regras, o ponto principal é que
**mesmo uma IA "convencida" não tem capacidade de causar dano**: ela só pode chamar as ferramentas permitidas
com argumentos válidos. A resposta é renderizada com DOMPurify (sem XSS), telefones saem mascarados das
consultas e erros internos nunca são repassados à IA ou ao usuário.

### 3. Você permitiria que a IA escrevesse SQL diretamente? Por quê?

Não, em um produto multiestabelecimento com dados de clientes. Mesmo com usuário somente leitura, SQL livre
permite: ler dados de outras lojas ou colunas sensíveis, consultas caras que derrubam o banco (joins sem índice,
varreduras completas), resultados errados com aparência correta (a IA erra filtros como status cancelado) e
torna impossível auditar/testar o comportamento. Ferramentas tipadas dão **segurança, desempenho previsível
(consultas com índice), números testáveis e definições consistentes** (o que é "faturamento" é definido uma vez).

Faria sentido apenas num cenário controlado — por exemplo, um analista interno usando uma réplica isolada,
com usuário só-leitura, *views* restritas, timeout de consulta e validação/parse do SQL antes de executar.

### 4. Como essa solução funcionaria com 100 milhões de pedidos?

A arquitetura (IA → ferramentas → consultas agregadas) continua igual; muda a camada de dados:

- **Tabelas pré-agregadas** (ex.: `daily_store_sales`, `daily_product_sales`, `customer_stats`) atualizadas por jobs
  em fila/eventos. As ferramentas passam a ler milhares de linhas em vez de milhões.
- **Particionamento** de `orders` por data e, conforme o porte, *sharding* por loja.
- **Réplicas de leitura** para analytics, separando da operação de pedidos.
- Para análises mais pesadas, um banco analítico/colunar (ClickHouse, BigQuery) alimentado por CDC.
- **Cache** (Redis) dos resultados por loja + período e índices revisados com `EXPLAIN`.

O custo da IA praticamente não muda: ela recebe resultados agregados, não registros.

### 5. Como reduziria o custo da IA com 2.000 estabelecimentos usando diariamente?

- **Modelo pequeno por padrão** (Flash/Flash-Lite) e roteamento: perguntas simples de métrica poderiam até ser
  respondidas por *templates* sem IA; modelos maiores só para análises complexas.
- **Context caching** do *system prompt* + declarações de ferramentas (parte fixa e maior do prompt).
- **Cache de respostas** para perguntas idênticas da mesma loja no mesmo intervalo (ex.: "quanto vendi hoje").
- Resultados de ferramentas **compactos** (limites, campos necessários) e memória curta (só texto, poucas mensagens).
- **Insights pré-gerados em lote** (Batch API, ~50% mais barata) fora do horário de pico, como um resumo diário.
- **Cotas por estabelecimento** (rate limit diário) e monitoramento de custo por loja para identificar abusos.

### 6. Onde utilizaria cache?

- **Resultados das ferramentas** (já implementado): chave `loja + ferramenta + argumentos`, TTL configurável
  (`ANALYTICS_CACHE_TTL`). Perguntas diferentes que usam os mesmos dados não repetem a consulta.
- **Indicadores do dashboard** (já implementado).
- Agregados históricos (meses fechados não mudam: TTL longo ou invalidação por evento de novo pedido).
- Respostas completas para perguntas repetidas e *context caching* do provedor de IA.
- Em produção, o driver seria **Redis** (`CACHE_STORE=redis`), compartilhado entre instâncias.

### 7. Como monitoraria o consumo de tokens e custo da IA?

Cada resposta grava `prompt_tokens`, `output_tokens` (incluindo tokens de raciocínio), `cost_usd` (pela tabela de
preços em `config/ai.php`), `model` e `latency_ms`, e gera um log estruturado `ai.answer`. O endpoint
`/api/usage` e a barra lateral mostram consumo do dia e por modelo. Em produção: enviar essas métricas a um
sistema de observabilidade (Grafana/Datadog), com painéis por loja/modelo e **alertas** de custo diário,
taxa de erro e latência, além de orçamento por estabelecimento.

### 8. Como registraria as perguntas e respostas realizadas pelos usuários?

Já implementado nas tabelas `conversations` e `messages`: pergunta, resposta, status (ok/erro), ferramentas
chamadas com argumentos e duração, gráficos, resultado da verificação de números, modelo, tokens, custo e
latência. Isso permite auditoria, depuração e avaliação da qualidade (ex.: perguntas sem ferramenta adequada
viram novas ferramentas). Em produção acrescentaria: usuário autenticado, política de retenção/anonimização
(LGPD) e feedback 👍/👎 por resposta.

### 9. Como trataria uma indisponibilidade do provedor de IA?

- **Timeout** de conexão e de resposta configuráveis.
- **Fallback automático** para outro modelo quando o erro é temporário (408/429/5xx/conexão) — feito antes do
  primeiro token, para não duplicar texto no streaming.
- **Mensagem amigável** ao usuário (sem detalhes técnicos), registro do erro e a mensagem salva com status `error`.
- O restante do sistema (indicadores, histórico, campanhas) continua funcionando, porque não depende da IA.
- Próximos passos: *circuit breaker* (parar de chamar um provedor que está falhando por alguns minutos),
  fallback para outro provedor e respostas sem IA para perguntas de métrica simples.

### 10. O que faria diferente se tivesse mais tempo?

- Autenticação e perfis por estabelecimento (hoje a demo usa uma loja e visitantes anônimos).
- Tabelas pré-agregadas e Redis; filas para tarefas pesadas (ex.: geração de campanhas em lote).
- Avaliação automática de qualidade: um conjunto de perguntas com respostas esperadas, rodado a cada mudança
  de prompt/modelo (*evals*), e feedback do usuário nas respostas.
- Envio real das campanhas (WhatsApp/e-mail) com aprovação humana e opt-out.
- Mais análises: frequência de compra por cliente, cohort/retenção, cesta de produtos (o que é comprado junto),
  previsão de demanda.
- *Circuit breaker*, painel de observabilidade e testes end-to-end do front.

---

## Segurança — resumo

| Item | Como foi tratado |
|---|---|
| Validação de entrada | `ChatRequest` (tamanho, controle de caracteres, UUID) e regras por argumento de ferramenta. |
| Credenciais | Somente no `.env` (fora do Git); `.env.example` sem segredos; chave enviada em header, não na URL. |
| SQL Injection | Nenhum SQL é montado com texto do usuário ou da IA; query builder com bindings. |
| Prompt Injection | IA sem acesso direto a dados; ferramentas fechadas e validadas; loja vem do servidor; instruções de segurança. |
| Exposição de dados | Telefones mascarados; erros internos ocultos (`APP_DEBUG=false`); conversas isoladas por visitante. |
| Ações da IA | Só rascunho de campanha, liga/desliga por configuração, limite por hora, nunca envia. |
| Abuso/custo | Rate limit por minuto e por dia; CSRF; cabeçalhos de segurança. |
