# Help Desk Pro

Aplicação web para gerenciamento de chamados de suporte técnico. Centraliza as solicitações, organiza os atendimentos, distribui responsabilidades, controla os prazos de SLA, mantém o histórico das interações e disponibiliza indicadores operacionais.

Monólito em Laravel com Inertia.js e Vue 3, com controle de acesso por papéis e permissões. Interface em português brasileiro (pt-BR).

---

## Sumário

- [Funcionalidades](#funcionalidades)
- [Perfis e permissões](#perfis-e-permissões)
- [Ciclo de vida do chamado](#ciclo-de-vida-do-chamado)
- [Stack tecnológica](#stack-tecnológica)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Requisitos](#requisitos)
- [Instalação local (XAMPP)](#instalação-local-xampp)
- [Instalação com Docker](#instalação-com-docker)
- [Configuração](#configuração)
- [Comandos úteis](#comandos-úteis)
- [Testes](#testes)
- [Limitações conhecidas](#limitações-conhecidas)
- [Documentação interna](#documentação-interna)
- [Licença](#licença)

---

## Funcionalidades

**Chamados**

- Abertura de chamados pelo solicitante, com identificador público no formato `HD-XXXXXXXX`.
- Listagem com filtros, pesquisa, ordenação e paginação.
- Atribuição manual a um técnico e autoatribuição por técnicos.
- Alteração de status conforme as transições permitidas, de prioridade e de categoria.
- Resolução, reabertura e encerramento, com registro do motivo.
- Pendências com motivo e data prevista de retorno (aguardando solicitante, terceiros ou outros departamentos).
- Comentários públicos e notas internas, com histórico de versões dos comentários.
- Anexos (documentos e imagens) em chamados e mensagens, armazenados em área privada.
- Exportação da listagem em CSV, restrita a administradores e superadministradores.

**SLA**

- Políticas de SLA por prioridade, com prazo de primeira resposta e de resolução.
- Pausa e retomada do relógio de resolução nos status de pendência.
- Mudança de prioridade com regras explícitas de recálculo do prazo.
- Registro automático de violações de chamados parados, por comando agendado.
- Histórico de pausas e de eventos que afetam o SLA de cada chamado.

**Administração**

- Usuários, departamentos e categorias de chamados.
- Papéis e matriz de permissões, editável pela administração.
- Consulta ao catálogo de permissões.
- Políticas de SLA.

**Indicadores e auditoria**

- Visão geral (dashboard) com indicadores restritos ao escopo da listagem de chamados.
- Painel de desempenho dos técnicos: chamados atendidos e resolvidos, tempos médios e cumprimento de SLA.
- Histórico de auditoria, global (administração) e por chamado.

**Autenticação**

- Login, recuperação de senha, verificação de e-mail e confirmação de senha para ações sensíveis.

---

## Perfis e permissões

O acesso é definido por papel. Cada papel recebe um conjunto de permissões, que pode ser ajustado pela tela de administração de papéis e permissões.

| Papel | Visão e atuação principal |
|---|---|
| **Solicitante** | Abre chamados e acompanha apenas os próprios. Não vê prioridade, SLA nem o campo solicitante na listagem, nos detalhes e nos filtros (RF-24). |
| **Técnico** | Assume chamados disponíveis, altera status, prioridade e categoria, resolve chamados, adiciona notas internas e consulta a auditoria dos chamados que atende. |
| **Gestor** | Visão de todos os departamentos, atribui e reatribui chamados, encerra chamados, transfere departamento e consulta o painel de desempenho. |
| **Administrador** | Gestor de todo o sistema: além das permissões do gestor, gerencia usuários, departamentos, categorias, papéis, permissões e políticas de SLA. |

Permissões do catálogo (identificadores usados no código):

| Área | Permissões |
|---|---|
| Chamados | `tickets.view_all_departments`, `tickets.assign`, `tickets.reassign`, `tickets.self_assign`, `tickets.change_status`, `tickets.resolve`, `tickets.close`, `tickets.change_priority`, `tickets.change_category`, `tickets.transfer_department`, `tickets.view_internal_notes`, `tickets.add_internal_note` |
| Comentários | `comments.edit_others` |
| Auditoria e relatórios | `audit.view`, `reports.view_performance` |
| Administração | `users.manage`, `departments.manage`, `categories.manage`, `roles.manage`, `sla.manage` |

A autorização é feita nas policies, nos controllers e nos form requests. Ocultar um botão na tela não substitui essa verificação.

---

## Ciclo de vida do chamado

Os status seguem o RF-10 do PRD:

| Status | Descrição |
|---|---|
| Aberto | Chamado recém-criado, sem atendimento iniciado. |
| Em atendimento | Chamado com responsável trabalhando nele. O relógio de resolução está correndo. |
| Aguardando solicitante | Pendência: depende de retorno do solicitante. Pausa o SLA de resolução. |
| Pendente | Pendência genérica. Pausa o SLA de resolução. |
| Pendente Terceiros | Pendência com fornecedor ou terceiro (nome, protocolo, responsável). Pausa o SLA. |
| Pendente Departamentos | Pendência com outro departamento interno. Pausa o SLA. |
| Resolvido | Atendimento concluído, com registro da resolução. |
| Encerrado | Chamado fechado. Não aceita novas transições. |

Transições que não estão no fluxo são recusadas pelo backend, independentemente da tela. Um chamado resolvido pode ser reaberto (volta a *Aberto*) ou encerrado.

Prioridades: Baixa, Média, Alta e Crítica. A lista é provisória e está concentrada em um único enum (`app/Enums/TicketPriority.php`).

---

## Stack tecnológica

| Camada | Tecnologia |
|---|---|
| Backend | Laravel 12, PHP 8.2 ou superior (a imagem Docker usa PHP 8.3) |
| Integração front/back | Inertia.js 2 e Ziggy (rotas nomeadas no front) |
| Frontend | Vue 3 com TypeScript |
| Estilo | Tailwind CSS 3, fonte Figtree |
| Build | Vite 7 |
| Banco de dados | MariaDB (MySQL compatível) |
| Testes | Pest 3 sobre PHPUnit, com SQLite em memória |
| Ambiente | XAMPP no Windows ou Docker Compose |

---

## Estrutura do projeto

```
app/
├── Actions/          Operações de negócio (ex.: AssignTicket, ResolveTicket, UpdateRolePermissions)
├── Console/Commands/ Comandos agendados (RecordSlaBreaches)
├── Enums/            Status, prioridades, fluxo de transições e tipos de evento
├── Http/
│   ├── Controllers/  Um controller por recurso ou ação de chamado
│   ├── Requests/     Validação e autorização de entrada
│   └── Middleware/   HandleInertiaRequests, compartilha dados com o front
├── Models/           Eloquent (Ticket, TicketComment, Role, SlaPolicy, AuditLog...)
├── Policies/         Autorização por recurso
├── Support/          Regras transversais (SlaClock, TicketListingQuery, TicketCsvExport, PerformanceReport...)
database/
├── migrations/       Esquema do banco
└── seeders/          Papéis, permissões e usuário de teste
resources/js/
├── Pages/            Telas Inertia (Tickets, Admin, Audit, Performance, SlaPolicies, Auth, Profile)
├── Components/       Componentes reutilizáveis
├── Layouts/          Layouts autenticado e de visitante
└── composables/      Lógica compartilhada (ex.: toast)
routes/
├── web.php           Rotas da aplicação, com a autorização descrita em comentários
├── auth.php          Login, cadastro, recuperação de senha e verificação de e-mail
└── console.php       Agendamento (sla:record-breaches a cada minuto)
tests/
├── Feature/          Testes de fluxo HTTP, por módulo
└── Unit/             Regras puras (SlaClock, transições de status)
```

---

## Requisitos

- PHP 8.2 ou superior, com as extensões `pdo_mysql`, `mbstring`, `fileinfo`, `zip`, `gd`, `intl` e `bcmath`.
- Composer 2.
- Node.js 20 ou superior e npm.
- MariaDB 10.6 ou superior (ou MySQL 8).

Para Docker, basta Docker Desktop com Compose.

---

## Instalação local (XAMPP)

1. Clone o repositório em `C:\xampp\htdocs\helpdesk`.

2. Instale as dependências:

   ```bash
   composer install
   npm install
   ```

3. Crie o arquivo de ambiente e gere a chave da aplicação:

   ```bash
   copy .env.example .env
   php artisan key:generate
   ```

4. Crie o banco `helpdesk` no MariaDB (phpMyAdmin ou linha de comando) e confira as variáveis `DB_*` no `.env`.

5. Execute as migrations e os seeders:

   ```bash
   php artisan migrate --seed
   ```

   O seeder cria os papéis e as permissões (`RolesAndPermissionsSeeder`) e um usuário de teste: `test@example.com` com senha `password`. Troque a senha ou remova o usuário antes de qualquer uso real.

6. Ajuste os limites de upload do PHP para os anexos (ver [Configuração](#configuração)).

7. Rode o front-end e acesse a aplicação:

   ```bash
   npm run dev
   ```

   Acesse `http://localhost/helpdesk/public` (ou o endereço configurado no Apache do XAMPP). Em desenvolvimento, `npm run dev` mantém o Vite ativo para recarregar as alterações.

8. Para o SLA funcionar, agende `php artisan schedule:run` para rodar a cada minuto no Agendador de Tarefas do Windows. Sem isso, as violações de chamados parados não são registradas.

9. Para os e-mails (verificação de conta e recuperação de senha) chegarem à caixa de entrada local, rode o [Mailpit](docs/configuracao-email.md) ou mantenha `MAIL_MAILER=log` e leia o log em `storage/logs`.

Para o envio de e-mail e as filas, mantenha também um worker rodando:

```bash
php artisan queue:work
```

---

## Instalação com Docker

O `docker-compose.yml` sobe quatro serviços:

| Serviço | Função |
|---|---|
| `app` | Aplicação em Apache e PHP 8.3, na porta **8080** |
| `queue` | Worker de filas (`queue:work`) |
| `scheduler` | Executa `schedule:run` a cada minuto (motor de SLA) |
| `db` | MariaDB 11 com volume persistente |

Passos:

1. Copie o modelo e ajuste as senhas:

   ```bash
   copy .env.docker.example .env.docker
   ```

2. Gere a `APP_KEY` e coloque o valor em `.env.docker`:

   ```bash
   docker compose run --rm --no-deps -e APP_KEY=base64:x app php artisan key:generate --show
   ```

3. Suba os serviços:

   ```bash
   docker compose up -d --build
   ```

   O entrypoint aguarda o banco, roda as migrations e limpa os caches em desenvolvimento.

4. Para popular os papéis e as permissões na primeira vez:

   ```bash
   docker compose exec app php artisan db:seed --force
   ```

Acesse em `http://localhost:8080`.

**Desenvolvimento com Docker:** o `docker-compose.override.yml` é carregado automaticamente. Ele monta o código-fonte nos contêineres (sem rebuild a cada edição) e sobe o Vite com hot reload em `http://localhost:5173`. Para produção, use `docker compose -f docker-compose.yml up -d`.

---

## Configuração

As variáveis principais estão em `.env.example` (local) e `.env.docker.example` (Docker).

| Variável | Uso |
|---|---|
| `APP_URL` | Endereço público da aplicação. Usado em links de e-mail. |
| `DB_*` | Conexão com o banco. |
| `MAIL_*` | Envio de e-mail. Em produção, configure um serviço oficial (ver [docs/configuracao-email.md](docs/configuracao-email.md)). |
| `SEED_SUPERADMIN_EMAIL` | Opcional, só em ambiente local. Indica o e-mail que recebe o papel de superadministrador de teste (`LocalSuperAdminSeeder`). Esse papel não é criado fora de `local`. |
| `ATTACHMENTS_MAX_FILE_KB` | Tamanho máximo por arquivo. Padrão: 10 MB. |
| `ATTACHMENTS_MAX_FILES` | Arquivos por envio. Padrão: 5. |
| `ATTACHMENTS_MAX_UPLOAD_KB` | Soma dos arquivos de um envio. Padrão: 20 MB. |
| `ATTACHMENTS_MAX_FILES_PER_TICKET` | Arquivos por chamado. Padrão: 20. |
| `ATTACHMENTS_MAX_TICKET_KB` | Soma dos arquivos de um chamado. Padrão: 100 MB. |

**Anexos:** o PHP recusa uploads acima de `upload_max_filesize` e `post_max_size` antes do Laravel ver a requisição. Os valores precisam ser maiores que os da aplicação: pelo menos 10 MB por arquivo e mais de 20 MB por envio. No Docker, isso já está em `docker/php.ini`. No XAMPP, ajuste o `php.ini` e reinicie o Apache. Detalhes em [docs/anexos.md](docs/anexos.md).

**Tipos aceitos:** PDF, TXT, CSV, DOCX, XLSX, PNG, JPG/JPEG e WEBP. A conferência é pelo conteúdo do arquivo, não pela extensão. SVG, HTML, scripts e executáveis são recusados.

**Armazenamento:** os anexos ficam em `storage/app/private/attachments`, fora de `public/`, e são entregues apenas pela rota autorizada de download. Faça o backup do banco e dessa pasta juntos.

---

## Comandos úteis

```bash
# Setup completo (dependências, .env, chave, migrations e build)
composer run setup

# Ambiente de desenvolvimento: servidor, fila, logs e Vite
composer run dev

# Testes
composer test

# Build do front-end
npm run build

# Reexecutar o motor de SLA manualmente
php artisan sla:record-breaches

# Reexecutar apenas os seeders (idempotentes)
php artisan db:seed
```

---

## Testes

A suíte usa Pest sobre PHPUnit. Os testes de fluxo ficam em `tests/Feature` (por módulo: chamados, SLA, administração, auditoria, autenticação) e as regras puras, em `tests/Unit`. Os testes rodam em SQLite em memória, configurado em `phpunit.xml`.

```bash
composer test
# ou, para um módulo específico:
php artisan test --filter=TicketSla
```

Antes de publicar uma alteração, rode a suíte e `npm run build`.

---

## Limitações conhecidas

- Não há varredura antivírus nos anexos. A detecção de PDF e Office perigosos é por inspeção do conteúdo e não substitui um scanner.
- Anexos não podem ser excluídos pela interface, por rastreabilidade.
- SLA é calculado em horas corridas, sem horário comercial, feriados ou calendários personalizados.
- Não há escalonamento automático de chamados atrasados.
- Não há integração com e-mail, WhatsApp ou Telegram para abertura de chamados.
- O projeto é uma aplicação de instância única, sem multi-tenancy.

---

## Documentação interna

A pasta `docs/` contém PRD, regras de negócio, design system, diagramas, roteiros de teste e relatórios de QA. Ela **não é versionada** (está no `.gitignore`) e fica disponível apenas no ambiente local de desenvolvimento.

Documentos principais:

- `docs/prd-rn.md`: requisitos funcionais, regras de negócio e matriz de permissões.
- `docs/design-system.md`: tokens, cores e padrões visuais.
- `docs/configuracao-email.md`: configuração de e-mail em desenvolvimento e produção.
- `docs/anexos.md`: limites, tipos aceitos e configuração do PHP para anexos.

---

## Licença

Definir antes da publicação. O `composer.json` ainda herda a licença MIT do esqueleto Laravel.
