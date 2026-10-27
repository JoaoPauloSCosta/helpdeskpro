# Visão geral

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md).

## Objetivo

O Help Desk Pro é uma aplicação web para gerenciar chamados de suporte técnico. Centraliza as solicitações, distribui o atendimento entre técnicos, controla prazos de SLA, guarda o histórico de cada interação e oferece indicadores operacionais. É um **monólito Laravel 12 com Inertia.js 2 e Vue 3 (TypeScript)**, com interface em português do Brasil e controle de acesso por papéis e permissões configuráveis em tela.

**Problema que resolve.** Em operações de suporte, solicitações se dispersam, ficam sem responsável, sem histórico confiável e sem controle de prazos. O sistema cobre o ciclo completo do chamado: abertura, atribuição, atendimento, pendências, resolução, reabertura e encerramento, com rastreabilidade de cada evento.

## Escopo

| Dentro do escopo (comprovado no código) | Fora do escopo (não encontrado no código) |
|---|---|
| Ciclo completo do chamado com máquina de estados | Notificações de chamado por e-mail (só existem o convite de usuário e os e-mails de senha) |
| Comentários públicos, notas internas e histórico de versões (leitura) | Edição de comentário pela interface (a Action existe, sem rota nem tela) |
| Anexos com inspeção por conteúdo | Exclusão de anexos e varredura antivírus |
| SLA com políticas por prioridade, pausas e violações | Calendário comercial e feriados (os prazos são em minutos corridos) |
| Dashboard, painel de desempenho e exportação CSV | API para terceiros e integrações externas |
| Administração de usuários, departamentos, categorias, papéis, permissões e SLA | Filas de trabalho em uso (não há jobs) |
| Auditoria global e por chamado | Verificação de e-mail obrigatória (ver [10-debitos-tecnicos.md](10-debitos-tecnicos.md), T5) |

## Público e perfis

| Perfil | Papel semeado | O que faz (padrão do seeder) |
|---|---|---|
| Solicitante | `requester` | Abre chamados, acompanha só os próprios, comenta, anexa arquivos e reabre um chamado resolvido que abriu. Não vê prioridade, SLA nem o campo "solicitante" (visão restrita). |
| Técnico | `technician` | Assume chamados abertos, muda status, registra pendências, resolve, altera prioridade e categoria, usa notas internas e consulta a auditoria dos chamados que acessa. |
| Gestor | `manager` | Vê todos os departamentos, atribui e reatribui, encerra, transfere departamento e consulta o painel de desempenho. |
| Administrador | `admin` | Gerencia usuários, departamentos, categorias, papéis, permissões e políticas de SLA; consulta auditoria e desempenho; exporta CSV. Não recebe as permissões operacionais de chamado por padrão. |

Como os papéis são dados no banco, a matriz acima é a **padrão do seeder**, não necessariamente a de uma instalação em uso. Detalhes em [05-rotas-e-permissoes.md](05-rotas-e-permissoes.md) e [casos-de-uso.md](casos-de-uso.md).

## Módulos e situação

| Módulo | Situação | Observação |
|---|---|---|
| Autenticação (login, recuperação de senha, cadastro público) | Implementado | Login com limitador de 5 tentativas. O cadastro público sempre cria um Solicitante. |
| Verificação de e-mail | Parcial | Rotas e telas existem, mas o `User` não implementa `MustVerifyEmail`; o middleware `verified` não bloqueia ninguém. |
| Chamados (abertura, listagem, detalhe, edição, atribuição, autoatribuição, status, pendência, resolução, reabertura, encerramento) | Implementado | Máquina de estados em `TicketStatus::transitions()`. |
| Comentários e notas internas | Implementado | Histórico de versões somente leitura. |
| Edição de comentário | Parcial | `EditTicketComment` só é usada em testes. |
| Anexos | Implementado | Disco privado, download autorizado a cada requisição. |
| SLA | Implementado | Pausas, recálculo, violações por comando agendado. |
| Dashboard e desempenho | Implementado | Respeitam o escopo de visibilidade do usuário. |
| Exportação CSV | Implementado | Restrita aos papéis `admin` e `superadmin`. |
| Administração | Implementado | Convite de usuário por e-mail de definição de senha. |
| Auditoria global e por chamado | Implementado | Eventos imutáveis. |

## Tecnologias

Versões obtidas de `composer.lock` e dos `package.json` instalados.

| Camada | Tecnologia e versão |
|---|---|
| Linguagem e framework | PHP `^8.2` (local 8.2.4; imagem Docker 8.3), Laravel 12.70.0 |
| Ponte backend-frontend | Inertia 2.0.28 (Laravel) e 2.3.29 (Vue), Ziggy 2.6.5 |
| Frontend | Vue 3.5.44, TypeScript 5.9.3 (`strict`), vue-tsc 2.2.12, Vite 7.3.7, Tailwind CSS 3.4.19, Axios 1.20.0 |
| Banco | MariaDB (Docker: `mariadb:11`); testes em SQLite em memória |
| Testes e estilo | Pest 3.8.7 sobre PHPUnit 11.5.56; Pint 1.30.4 instalado, sem configuração |
| Infraestrutura | Docker Compose (Apache + PHP 8.3, MariaDB 11, Node 22 para o Vite), ou XAMPP no Windows |

Declarados e sem uso: `laravel/sanctum` e `@tailwindcss/vite ^4` (ver T18 em [10-debitos-tecnicos.md](10-debitos-tecnicos.md)).

## Números do repositório

Comprovados nesta análise, salvo indicação.

- 34 commits de um único autor (Git), de 21 a 27/10/2026. **32 deles terminam com `Co-Authored-By: Claude`** (conferido no histórico).
- 18 migrations e 21 tabelas; 20 permissões e 4 papéis semeados (mais o `superadmin` local).
- 71 rotas segundo `php artisan route:list` em ambiente local (inclui `/design-system`, só registrada em `local`): 55 em `routes/web.php`, 15 em `routes/auth.php` e 1 do design system.
- 61 arquivos em `tests/`. **Execução de 27/10/2026: 1.486 testes aprovados, 0 falhas, 9.596 asserções, 122,78 s.** `npm run build` sem erros.

## Documentos relacionados

Referência funcional de casos de uso: [casos-de-uso.md](casos-de-uso.md). O PRD (v1.8) e as regras de negócio ficam em `docs/`, fora do Git; o código cita seus identificadores (RF-xx, CHA-xx, ADM-xx, SLA-xx, PER-xx) em comentários.
