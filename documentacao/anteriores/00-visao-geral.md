# Help Desk Pro: visão geral

> Documentação técnica produzida a partir de uma auditoria do código em 27/10/2026 (último commit analisado: `bdb7667`). Cada afirmação aponta para o arquivo que a sustenta. Quando algo não foi comprovado, o texto diz isso.

## O que é

Aplicação web para gerenciar chamados de suporte técnico. Centraliza as solicitações, distribui o atendimento entre técnicos, controla prazos de SLA, guarda o histórico das interações e oferece indicadores operacionais. É um **monólito Laravel 12 com Inertia.js 2 e Vue 3 (TypeScript)**, com interface em português do Brasil e controle de acesso por papéis e permissões configuráveis em tela.

## Problema que resolve

Em operações de suporte, solicitações se dispersam, ficam sem responsável, sem histórico confiável e sem controle de prazos. O sistema cobre o ciclo completo: abertura, atribuição, atendimento, pendências, resolução, reabertura e encerramento, com rastreabilidade de cada evento.

## Perfis

| Perfil | Papel semeado | O que faz |
|---|---|---|
| Solicitante | `requester` | Abre chamados, acompanha só os próprios, comenta, anexa arquivos e reabre um chamado resolvido que abriu. Não vê prioridade, SLA nem o campo "solicitante" (visão restrita). |
| Técnico | `technician` | Assume chamados abertos, muda status, registra pendências, resolve, altera prioridade e categoria, usa notas internas e consulta a auditoria dos chamados que acessa. |
| Gestor | `manager` | Vê todos os departamentos, atribui e reatribui, encerra, transfere departamento e consulta o painel de desempenho. |
| Administrador | `admin` | Gerencia usuários, departamentos, categorias, papéis, permissões e políticas de SLA, e consulta auditoria e desempenho. Não recebe as permissões operacionais de chamado por padrão. |

A matriz completa está em [04-rbac-e-autorizacao.md](04-rbac-e-autorizacao.md). Como os papéis são dados no banco, a administração pode alterá-los, então a matriz acima é a **padrão do seeder**, não necessariamente a de uma instalação em uso.

Os atores do diagrama de casos de uso (Visitante, Usuário autenticado, Equipe de atendimento, Servidor de e-mail, Agendador etc.), a ligação de cada caso de uso ao código e as 9 divergências encontradas entre o diagrama e a implementação estão em [casos-de-uso.md](../casos-de-uso.md). Duas merecem destaque: o Solicitante não tem visão geral (o dashboard o redireciona) e a auditoria global exige também `tickets.view_all_departments`.

## Funcionalidades

| Módulo | Situação | Observação |
|---|---|---|
| Autenticação (login, recuperação de senha, cadastro público) | Implementado | Login com limitador de 5 tentativas. O cadastro público sempre cria um Solicitante. |
| Verificação de e-mail | Parcial | As rotas e telas existem, mas o `User` não implementa `MustVerifyEmail`, então o middleware `verified` não bloqueia ninguém. Ver [10-decisoes-e-debitos.md](10-decisoes-e-debitos.md). |
| Chamados: abertura, listagem, detalhe, edição, atribuição, autoatribuição, status, pendência, resolução, reabertura, encerramento | Implementado | Máquina de estados em `TicketStatus::transitions()`. |
| Comentários públicos e notas internas | Implementado | Com histórico de versões (leitura). |
| Edição de comentário | Parcial | Existe a Action `EditTicketComment` (usada só nos testes). Não há rota nem tela para editar. |
| Anexos | Implementado | Inspeção por conteúdo, disco privado, download autorizado a cada requisição. |
| SLA | Implementado | Políticas por prioridade, pausas, recálculo, violações por comando agendado. |
| Dashboard e painel de desempenho | Implementado | Respeitam o escopo de visibilidade do usuário. |
| Exportação CSV | Implementado | Restrita aos papéis `admin` e `superadmin`, com proteção contra injeção de fórmulas. |
| Administração (usuários, departamentos, categorias, papéis, matriz de permissões, catálogo) | Implementado | Convite de usuário por e-mail de redefinição de senha. |
| Auditoria global e por chamado | Implementado | Eventos imutáveis. |
| Notificações de chamado por e-mail, filas, integrações externas | Não encontrado | Só o e-mail de convite e os e-mails de senha existem. |

## Números do repositório

Contagens feitas na auditoria (estáticas, salvo onde indicado):

- 34 commits de um único autor, entre 21/10/2026 e 27/10/2026.
- 18 migrations e 21 tabelas.
- 20 permissões e 4 papéis semeados (mais o papel local `superadmin`).
- 55 rotas em `routes/web.php` e 15 em `routes/auth.php` (conferido depois com `route:list`), além de `/design-system`, registrada só em ambiente local.
- Cerca de 35 classes em `app/Actions` (inclui as exceções de conflito).
- 61 arquivos em `tests/`. **Execução real em 27/10/2026: 1.486 testes aprovados, 0 falhas, 9.596 asserções, 122,78 s.**
- `npm run build` (`vue-tsc` e Vite) sem erros.

## Como ler esta documentação

| Arquivo | Conteúdo |
|---|---|
| [casos-de-uso.md](../casos-de-uso.md) | Atores, 30 casos de uso por módulo ligados ao código, matriz padrão e divergências com a referência funcional. |
| [01-arquitetura.md](01-arquitetura.md) | Camadas, fluxo ponta a ponta e padrões usados. |
| [02-stack-e-execucao.md](02-stack-e-execucao.md) | Versões, instalação (XAMPP e Docker), agendador e variáveis. |
| [03-dominio-e-regras.md](03-dominio-e-regras.md) | Máquina de estados, SLA, visibilidade, anexos, numeração. |
| [04-rbac-e-autorizacao.md](04-rbac-e-autorizacao.md) | Permissões, papéis, policies e regras especiais. |
| [05-modelo-de-dados.md](05-modelo-de-dados.md) | Tabelas, relacionamentos e restrições. |
| [06-rotas-e-fluxos.md](06-rotas-e-fluxos.md) | Mapa de rotas e diagramas de sequência. |
| [07-frontend.md](07-frontend.md) | Páginas, componentes, composables e convenções. |
| [08-testes.md](08-testes.md) | Estratégia, resultados e limites. |
| [09-seguranca.md](09-seguranca.md) | Controles existentes e riscos conhecidos. |
| [10-decisoes-e-debitos.md](10-decisoes-e-debitos.md) | Decisões de projeto e dívida técnica. |
| [11-roteiro-de-entrevista.md](11-roteiro-de-entrevista.md) | Apresentação e perguntas prováveis. |

## Limites desta documentação

- O PRD, as regras de negócio (RF, RN), o diagrama de casos de uso (`docs/Casos-de-Uso-Help-Desk-Pro.html` e `docs/diagrama-casos-de-uso.puml`) e os demais documentos de requisitos ficam em `docs/`, que está no `.gitignore`. O código cita esses identificadores (por exemplo RF-15, CHA-07) em comentários; aqui eles aparecem quando ajudam a localizar a regra.
- O corpo das páginas Vue maiores e dos modais foi inspecionado só em parte. O que está descrito sobre o frontend vem de arquivos efetivamente lidos.
- Nenhuma execução contra MariaDB foi feita. Os testes usam SQLite em memória.
