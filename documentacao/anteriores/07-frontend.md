# Frontend

Vue 3 (Composition API, `<script setup lang="ts">`) servido pelo Inertia 2, com Tailwind 3 e rotas nomeadas pelo Ziggy. Cerca de 12,7 mil linhas em páginas, layouts e composables (contagem com `wc`).

## Entrada e build

- `resources/js/app.ts`: `createInertiaApp` resolve `./Pages/{nome}.vue` por `import.meta.glob`, instala o plugin do Inertia e o `ZiggyVue`. Título no formato `{título} - {nome do app}`.
- `resources/views/app.blade.php`: `@routes` (Ziggy), `@vite` com a página do componente corrente, `@inertia`. A fonte Figtree é carregada de `fonts.bunny.net` (dependência externa em execução).
- `vite.config.js`: `laravel-vite-plugin` e `@vitejs/plugin-vue`; em Docker (`VITE_DOCKER=true`) escuta em todas as interfaces e usa polling.
- `npm run build` = `vue-tsc && vite build`. O TypeScript está em modo `strict`, e o alias `@` aponta para `resources/js`.

## Estrutura

| Pasta | Conteúdo |
|---|---|
| `Layouts/` | `AuthenticatedLayout` (barra lateral fixa em telas largas, painel recolhível no celular, foco e `Esc` tratados), `AuthLayout` (telas de login e cadastro), `GuestLayout` (confirmação de senha, verificação de e-mail, página de erro). |
| `Pages/` | Uma página por tela, agrupadas por módulo. |
| `Components/` | 54 componentes reutilizáveis. |
| `composables/` | `useListing`, `useLeaveGuard`, `useToast`. |
| `lib/` | Tipos e utilitários por módulo (`ticket.ts`, `sla.ts`, `dashboard.ts`, `navigation.ts`, `attachments.ts`, etc.). |
| `types/` | Declarações globais (`global.d.ts`, `index.d.ts`, `vite-env.d.ts`). |

### Páginas (Pages)

| Módulo | Páginas |
|---|---|
| Autenticação | `Auth/Login`, `Register`, `ForgotPassword`, `ResetPassword`, `VerifyEmail`, `ConfirmPassword` |
| Chamados | `Tickets/Index` (678 linhas), `Create`, `Edit`, `Show` (613 linhas) e `Partials/` com 10 componentes (modais de atribuição, autoatribuição, status, pendência, resolução, reabertura, encerramento, histórico de comentário, histórico de SLA, auditoria) |
| Indicadores | `Dashboard`, `Performance/Index` |
| Administração | `Admin/Users`, `Departments`, `Categories`, `Roles` (inclui `Permissions.vue`, a matriz), `Permissions/Index` (catálogo) — cada uma com `Index`, `Create`, `Edit` quando aplicável |
| SLA | `SlaPolicies/Index`, `Create`, `Edit`, `Show` |
| Outras | `Audit/Index`, `Profile/Edit` (com 5 parciais), `Error`, `DesignSystem` (referência visual; a rota só existe em `local`) |

### Componentes por família

- **Estrutura:** `Header`, `Sidebar`, `Breadcrumbs`, `PageHeader`, `Card`, `Tabs`, `Pagination`, `DataTable`, `SortableHeader`.
- **Formulário:** `FormField`, `TextInput`, `Textarea`, `Select`, `Checkbox/CheckboxField`, `Radio/RadioField`, `Switch`, `SearchInput`, `IconInput`, `InputError`, `InputLabel`, `StatusRadioGroup`.
- **Ações:** `Button`, `PrimaryButton`, `Dropdown/DropdownLink`.
- **Feedback:** `Alert`, `Badge`, `StatusBadge`, `PriorityBadge`, `SlaIndicator`, `ToastContainer`, `Spinner`, `EmptyState`, `Modal`, `ConfirmModal`, `JustificationModal`.
- **Domínio:** `TicketNumber`, `Timeline/TimelineItem`, `CommentCard`, `AttachmentPicker`, `AttachmentList`, `StatCard`, `BarList`, `AdminSectionNav`, `BrandMark`, `ApplicationLogo`, `Icon`.
- Quatro componentes herdados do Breeze não têm uso: `DangerButton`, `NavLink`, `ResponsiveNavLink`, `SecondaryButton`.

## Composables

| Composable | O que faz |
|---|---|
| `useListing` | Pesquisa, filtros, ordenação e paginação **no servidor** via `router.get`, mantendo o formulário sincronizado com a URL (inclusive no voltar/avançar), ordenação só na URL quando difere do padrão, bloqueio durante carregamento e estado de erro com "tentar novamente" para falha de rede ou resposta 5xx. |
| `useLeaveGuard` | Confirma a saída de formulários com alterações não enviadas: navegações do Inertia abrem um modal; fechar a aba usa o aviso do navegador. |
| `useToast` | Fila de notificações (sucesso, informação, aviso, erro) com 5 s de duração e sem duplicar a mesma mensagem visível. |

## Navegação e permissões na interface

`lib/navigation.ts` descreve o menu como dados: cada item tem rota, padrões de "ativo" e as permissões exigidas. Um item só aparece se a rota existe no Ziggy (`route().has`) **e** o usuário tem todas as permissões listadas (`auth.permissions`, compartilhadas pelo `HandleInertiaRequests`). O comentário no código é explícito: isso só controla a exibição; a autorização real é do backend.

## Fluxo de dados

1. O controller envia props Inertia já filtradas por permissão (por exemplo, o objeto `can` da página de detalhes).
2. Ações do chamado são `POST/PUT` por modais; o servidor devolve redirecionamento com mensagens em `flash` (`success`, `info`, `warning`, `ticketCreated`), que o layout transforma em toasts.
3. Dados pesados são carregados sob demanda: `assignableTechnicians` usa `Inertia::optional` (recarga parcial quando o modal abre), e histórico de SLA, auditoria e versões de comentário vêm de endpoints JSON.
4. Formulários de criação enviam um `submission_token`; formulários de edição enviam a `version` carregada, para detecção de conflito.

## Boas práticas verificadas

Varredura em `resources/js` (arquivos `.vue` e `.ts`):

- Nenhum `v-html` nem `innerHTML`: o conteúdo de usuário é sempre escapado pelo Vue.
- Nenhum `localStorage` ou `sessionStorage`; nenhum `setInterval`; nenhum `console.log`.
- Um único `any` em todo o código (`Components/Checkbox.vue`).
- Uso consistente de tipos por módulo em `lib/*.ts`; opcionalidade explícita dos campos que somem na visão restrita.

## Limitações e acoplamentos

- O backend envia `status` e `priority` como **rótulos em português**. `lib/ticket.ts` mapeia cor e regras por esses textos e repete a lista de status e prioridades que também existe nos enums PHP. Renomear um rótulo no PHP quebra o mapeamento sem erro de compilação.
- Os nomes das prioridades estão marcados no código como ainda não confirmados no PRD.
- Não há testes de frontend (unitários nem de interface). A cobertura é a de `vue-tsc` mais testes de backend que verificam as props do Inertia.
- A leitura desta auditoria cobriu por inteiro os composables, o layout autenticado, `app.ts` e `navigation.ts`; páginas grandes e modais foram lidos só em parte.
