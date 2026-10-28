# Débitos técnicos

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md).

Problemas observados, impacto e melhorias sugeridas. A coluna "Situação" diz como cada item foi estabelecido: **Comprovado** (lido no código ou no fonte do framework), **Inferido** (consequência lógica, não executada) ou **Pendente** (depende de decisão ou de verificação). As sugestões são propostas desta análise, não decisões do responsável. Os identificadores T1 a T30 são estáveis (vêm da primeira versão, acrescidos de T27 a T30) e agora aparecem em ordem nas tabelas.

## Funcionais e de consistência

| # | Item | Situação | Impacto | Sugestão |
|---|---|---|---|---|
| T1 | Edição de comentário sem rota nem tela (`EditTicketComment` e `comments.edit_others` existem). | Comprovado | RF-11 (regras 5 a 10) só parcialmente atendido. | Implementar `PATCH` com autorização por autoria ou `comments.edit_others`, ou remover a permissão do catálogo. |
| T2 | Exportação CSV por slug de papel. | Comprovado | Foge do RBAC; papéis personalizados e Gestor não exportam. | Criar uma permissão (por exemplo, `tickets.export`) e migrar a regra. |
| T3 | `ProfileController::destroy` apaga o usuário de verdade. | Inferido | Erro de integridade para quem tem atividade; administrador pode se excluir. | Trocar por inativação ou bloquear a exclusão quando há vínculos. |
| T4 | Login de inativo não é bloqueado. | Comprovado (sem teste) | Sessão válida com permissões vazias. | Checar `is_active` na autenticação e cobrir com teste. |
| T5 | `verified` sem efeito. | Comprovado | Documentação promete verificação de e-mail. | Implementar `MustVerifyEmail` ou ajustar README e `docs/configuracao-email.md`. |
| T6 | `DatabaseSeeder` não é idempotente e cria `test@example.com`. | Comprovado | Falha na segunda execução; credencial conhecida em qualquer ambiente em que o seed rode. | Condicionar o usuário de teste ao ambiente `local` e usar `firstOrCreate`. |
| T7 | Cadastro público não gera evento de auditoria. | Comprovado | Pergunta OBS-003 do QA. | Decidir se deve auditar e registrar. |
| T8 | `UpdateTicketAttributes` sem reautorização sob lock. | Comprovado | Janela de corrida estreita. | Reautorizar dentro da transação como nas demais Actions. |

## Arquitetura e código

| # | Item | Situação | Sugestão |
|---|---|---|---|
| T9 | Anti-duplo-envio duplicado em `TicketController::store`. | Comprovado | Usar `SingleSubmission`. |
| T10 | Controllers CRUD de Category, Department, Role, SlaPolicy e User com estrutura quase idêntica. | Comprovado | Extrair comportamento comum com cuidado (cada um tem regras próprias). |
| T11 | `filterOptions` (chamados) e `options` (desempenho) repetem o mesmo carregamento de catálogos. | Comprovado | Extrair um helper. |
| T12 | `TicketPolicy::view` consulta o banco a cada chamada; `show` chama cerca de 13 `can()`. | Comprovado | Memoizar o resultado por requisição. |
| T13 | `PerformanceReport` carrega todos os chamados do período em memória. | Comprovado | Agregar em SQL ou processar em blocos. |
| T14 | Frontend acoplado a rótulos em português e com enums repetidos (`lib/ticket.ts`). | Comprovado | Enviar também o valor do enum e centralizar o mapeamento. |
| T15 | `match` sem `default` em `TicketAuditController::event` para todos os `TicketEvent`. | Comprovado | Adicionar teste que percorra `TicketEvent::cases()` e confira que a tela de auditoria os trata. |

## Higiene do repositório

| # | Item | Situação | Sugestão |
|---|---|---|---|
| T16 | `storage/framework/lsp-8ebd30caf6e53d1a.php` versionado no último commit (helper de IDE). | Comprovado | Remover do Git e ignorar `storage/framework/lsp-*`. |
| T17 | Resíduos do esqueleto: componentes sem uso, `ExampleTest` x2, helpers de exemplo em `Pest.php`, `composer.json` com nome `laravel/laravel` e licença MIT herdada. | Comprovado | Limpar e definir a licença. |
| T18 | `laravel/sanctum` e `@tailwindcss/vite` declarados e sem uso. | Comprovado | Remover as dependências. |
| T19 | `/docs` (PRD, regras, QA) e `helpdesk.sql` ignorados pelo Git. | Comprovado | Decidir o que publicar; nunca publicar o dump. |
| T20 | Sem CI nem lint (ESLint, Prettier, PHPStan; Pint sem configuração). | Comprovado | Adicionar pipeline mínimo com testes e build. |
| T21 | Testes escrevem no `laravel.log` real. | Comprovado | Definir `LOG_CHANNEL=null` ou `array` no `phpunit.xml`. |
| T22 | Testes só em SQLite; locks e SQL específico de MariaDB sem prova. | Comprovado | Rodar parte da suíte em MariaDB (container) no CI. |

## Rastreabilidade com a referência funcional

Detalhes e evidências em [casos-de-uso.md](casos-de-uso.md), seção 9.

| # | Item | Situação | Sugestão |
|---|---|---|---|
| T23 | A matriz do diagrama diverge do código em D1 (visão geral do Solicitante), D2 (auditoria global) e D3 (desempenho exige `view_all_departments`). | Comprovado | Corrigir o HTML e o `.puml` de `docs/` ou manter a divergência registrada; decisão do responsável pela referência. |
| T24 | "Equipe de atendimento" é ator no diagrama e permissão (`view_internal_notes`) no código; o Administrador cai de um lado e do outro (D6). | Comprovado | Decidir a regra e documentá-la; se necessário, criar uma permissão própria para "equipe". |
| T25 | O diagrama omite casos existentes no código: sair, excluir a própria conta, subconsultas do chamado, catálogo de permissões. | Comprovado | Acrescentar ao diagrama, ou remover a exclusão de conta (T3). |
| T26 | A referência cita só o Agendador do Windows; o projeto tem também o serviço `scheduler` no Docker, e o comando só roda se alguém executar `schedule:run`. | Comprovado | Registrar os dois ambientes e um modo de verificar que o agendador está ativo. |

## Documentação e requisitos em aberto

| # | Item | Situação | Sugestão |
|---|---|---|---|
| T27 | O `README.md` (linha 77) descreve o Administrador como o gestor "além das permissões do gestor" (ou seja, com as operacionais); o seeder e o PRD (7.4) não as concedem a ele. | Comprovado | Corrigir o README. |
| T28 | O `README.md` descreve `php artisan db:seed` como idempotente (o `DatabaseSeeder` não é), cita e-mail de verificação de conta (não é enviado) e worker de fila (não há jobs). | Comprovado | Corrigir o README ou alinhar o código (ver T5, T6). |
| T29 | O PRD (RF-21, regra 6) diz que a exportação "poderá ser auditada". Não há evento de exportação em `TicketEvent` nem em `AdminEvent`. | Comprovado | Decidir se a exportação deve gerar evento de auditoria e registrar. |
| T30 | Arquivos de `docs/` não comparados com o código: TRD, mapeamentos de rotas e de dados, diagramas de sequência, `anexos.md`, `design-system.md`, especificação de UI/UX, roteiro de teste manual e os dois `.docx` (lista no [README.md](README.md)). | Pendente | Comparar cada um com o capítulo indicado no README e registrar divergências. |

## Priorização sugerida

Proposta desta análise, sujeita à decisão do responsável.

| Faixa | Itens | Motivo |
|---|---|---|
| Antes de qualquer uso real | T6, T4, T3, T16, e rodar `composer audit` e `npm audit` | Credencial conhecida, acesso de inativo, exclusão inconsistente, arquivo indevido no Git, dependências não auditadas. |
| Coerência funcional | T1, T2, T5, T24, T27, T28, T29 | O código, o PRD e a documentação dizem coisas diferentes. |
| Robustez | T8, T22, T13, T12 | Concorrência, banco de produção e desempenho. |
| Manutenção | T9 a T11, T14, T15, T17 a T21, T30 | Duplicação, resíduos e ferramentas ausentes. |

## Hipóteses descartadas na leitura

- Rotas com ordem errada (`/tickets/export`, `/tickets/create`): estão antes de `/tickets/{ticket}`.
- Policies sem registro: usam a descoberta automática do Laravel (`App\Policies\XPolicy` para `App\Models\X`).
- Anexos servidos por URL pública: o disco `attachments` não tem `url` e o download passa por controller autorizado.
- Segredos versionados: `.env`, `.env.docker` e `helpdesk.sql` estão no `.gitignore` e fora da lista de arquivos rastreados.

## Observação do QA

`docs/relatorio-qa-helpdesk-pro.md` (QA parcial) traz a observação OBS-001: a tela de SLA mostra "Restam 2 min" em chamado pausado cuja violação já estava registrada. **Não foi reproduzida** nesta análise.
