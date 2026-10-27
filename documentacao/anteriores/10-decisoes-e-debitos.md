# Decisões de projeto e dívida técnica

## Decisões de projeto (resumo no formato ADR)

Cada decisão foi inferida do código e dos comentários; onde havia documento de origem, ele é citado. Itens marcados *(a confirmar)* dependem de você confirmar a motivação.

| # | Decisão | Motivo registrado ou inferido | Consequência |
|---|---|---|---|
| D1 | Monólito Laravel + Inertia + Vue, sem API separada. | PRD 3.2 (monólito); evita duplicar autorização e contratos. | Sem consumidores externos; `laravel/sanctum` ficou sem uso. |
| D2 | Padrão **Action** para regras transacionais; controllers finos. | Concentra transação, lock e reautorização. | Muita repetição de estrutura entre as Actions; consistência alta. |
| D3 | RBAC por permissões em tabelas, não por papel no código. | PRD 7: matriz editável pela administração. | A exportação CSV por papel é a exceção (D9). |
| D4 | Histórico e auditoria somente-inserção; FKs `restrictOnDelete`; inativar em vez de excluir. | RN-18, RN-19 (rastreabilidade). | A exclusão de conta própria fica em conflito com a regra (ver dívida T3). |
| D5 | Concorrência otimista por `editVersion` (hash dos campos) em vez de `updated_at`. | `updated_at` tem precisão de segundos e muda a cada comentário (comentário do código). | Formulários carregam e devolvem uma versão. |
| D6 | Metas de SLA **copiadas** para o chamado. | A política pode ser editada depois (migration `add_sla_columns`). | Mudar uma política não afeta chamados existentes. |
| D7 | Prazo de resolução **embute** as pausas (estendido na retomada). | RNF-10: sem dupla contagem de tempo. | O consumo sai de `meta − (prazo − instante do relógio)`. |
| D8 | Violação de SLA persistida (`*_breached_at`) e estado atual derivado. | RN-15: violação histórica não se apaga. | Um chamado pode estar "No prazo" e ter violação registrada. |
| D9 | Exportação CSV restrita a `admin` e `superadmin` por slug de papel. | Commit `c717ad5` (26/10/2026: o botão só aparece para esses papéis e os demais recebem 403), README e o diagrama de casos de uso, que a trata como exceção declarada. *(a confirmar a motivação)* | Foge do padrão (D3) e do PRD RF-21 ("filtros e permissões"). |
| D10 | Prioridade sempre Média na abertura. | V-D4 em `docs/pendencias.md` (24/10/2026). | Segundo `docs/pendencias.md`, no ambiente de desenvolvimento do autor só a prioridade Média tinha política de SLA ativa (não verificado no banco). |
| D11 | Visão restrita decidida no servidor (props ausentes, filtros `prohibited`). | RF-24; a interface só reflete. | Tipos opcionais no frontend. |
| D12 | SQLite em memória nos testes. | Velocidade (1.486 casos em ~2 min). | Lacunas de paridade com MariaDB. |
| D13 | Anexos em disco privado, validados por conteúdo e entregues por rota autorizada. | RF-23. | Sem antivírus; sem exclusão. |
| D14 | Cálculo de SLA em horas corridas. | README ("Limitações conhecidas"). | Sem calendário comercial. |

## Dívida técnica

Legenda de situação: **Confirmado** (lido no código ou no fonte do framework), **Inferido** (consequência lógica, não executada), **Resolvido na leitura** (hipótese descartada).

### Funcionais e de consistência

| # | Item | Situação | Impacto | Sugestão |
|---|---|---|---|---|
| T1 | Edição de comentário sem rota nem tela (`EditTicketComment` e `comments.edit_others` existem). | Confirmado | Requisito RF-11 (regras 5 a 10) só parcialmente atendido. | Implementar `PATCH` com autorização por autoria ou `comments.edit_others`, ou remover a permissão do catálogo. |
| T2 | Exportação CSV por slug de papel. | Confirmado | Foge do RBAC; papéis personalizados e Gestor não exportam. | Criar uma permissão (por exemplo, `tickets.export`) e migrar a regra. |
| T3 | `ProfileController::destroy` apaga o usuário de verdade. | Inferido | Erro de integridade para quem tem atividade; administrador pode se excluir. | Trocar por inativação ou bloquear a exclusão quando há vínculos. |
| T4 | Login de inativo não é bloqueado. | Confirmado (sem teste) | Sessão válida com permissões vazias. | Checar `is_active` na autenticação e cobrir com teste. |
| T5 | `verified` sem efeito. | Confirmado | Documentação promete verificação de e-mail. | Implementar `MustVerifyEmail` ou ajustar README e `docs/configuracao-email.md`. |
| T6 | `DatabaseSeeder` não é idempotente e cria `test@example.com`. | Confirmado | Falha na segunda execução; credencial conhecida em produção. | Condicionar o usuário de teste ao ambiente `local` e usar `firstOrCreate`. |
| T7 | Cadastro público não gera evento de auditoria. | Confirmado | Pergunta OBS-003 do QA. | Decidir se deve auditar e registrar. |
| T8 | `UpdateTicketAttributes` sem reautorização sob lock. | Confirmado | Janela de corrida estreita. | Reautorizar dentro da transação como nas demais Actions. |

### Rastreabilidade com a referência funcional

Itens vindos da integração com o diagrama de casos de uso (detalhes e evidências em [casos-de-uso.md](../casos-de-uso.md), seção 9).

| # | Item | Situação | Sugestão |
|---|---|---|---|
| T23 | A matriz do diagrama diverge do código em D1 (visão geral do Solicitante), D2 (auditoria global) e D3 (desempenho exige `view_all_departments`). | Confirmado | Corrigir o HTML e o `.puml` de `docs/` ou manter a divergência registrada; a decisão é do responsável pela referência. |
| T24 | "Equipe de atendimento" é um ator no diagrama e uma permissão (`view_internal_notes`) no código; o Administrador cai de um lado e do outro (D6). | Confirmado | Decidir a regra e documentá-la; se necessário, criar uma permissão própria para "equipe". |
| T25 | O diagrama omite casos existentes no código: sair, excluir a própria conta, subconsultas do chamado, catálogo de permissões. | Confirmado | Acrescentar ao diagrama, ou remover a exclusão de conta (T3). |
| T26 | A referência cita só o Agendador do Windows; o projeto tem também o serviço `scheduler` no Docker, e o comando só roda se alguém executar `schedule:run`. | Confirmado | Registrar os dois ambientes e um modo de verificar que o agendador está ativo. |

### Arquitetura e código

| # | Item | Situação | Sugestão |
|---|---|---|---|
| T9 | Anti-duplo-envio duplicado em `TicketController::store`. | Confirmado | Usar `SingleSubmission`. |
| T10 | Controllers CRUD de Category, Department, Role, SlaPolicy e User com estrutura quase idêntica. | Confirmado | Extrair comportamento comum com cuidado (cada um tem regras próprias). |
| T11 | `filterOptions` (chamados) e `options` (desempenho) repetem o mesmo carregamento de catálogos. | Confirmado | Extrair um helper. |
| T12 | `TicketPolicy::view` consulta o banco a cada chamada; `show` chama cerca de 13 `can()`. | Confirmado | Memoizar o resultado por requisição. |
| T13 | `PerformanceReport` carrega todos os chamados do período em memória. | Confirmado | Agregar em SQL ou processar em blocos. |
| T14 | Frontend acoplado a rótulos em português e com enums repetidos. | Confirmado | Enviar também o valor do enum e centralizar o mapeamento. |
| T15 | `match` sem `default` em `TicketAuditController::event` para todos os `TicketEvent`. | Confirmado | Adicionar um teste que percorra todos os `TicketEvent::cases()` e confira que a tela de auditoria os trata. |

### Higiene do repositório

| # | Item | Situação | Sugestão |
|---|---|---|---|
| T16 | `storage/framework/lsp-8ebd30caf6e53d1a.php` versionado no último commit (helper de IDE). | Confirmado | Remover do Git e ignorar `storage/framework/lsp-*`. |
| T17 | Resíduos do esqueleto: componentes sem uso, `ExampleTest` x2, helpers de exemplo em `Pest.php`, `composer.json` com nome `laravel/laravel` e licença MIT herdada. | Confirmado | Limpar e definir a licença. |
| T18 | `laravel/sanctum` e `@tailwindcss/vite` declarados e sem uso. | Confirmado | Remover as dependências. |
| T19 | `/docs` (PRD, regras, QA) e `helpdesk.sql` ignorados pelo Git. | Confirmado | Decidir o que publicar; nunca publicar o dump. |
| T20 | Sem CI nem lint (ESLint, Prettier, PHPStan; Pint sem configuração). | Confirmado | Adicionar pipeline mínimo com testes e build. |
| T21 | Testes escrevem no `laravel.log` real. | Confirmado | Definir `LOG_CHANNEL=null` ou `array` no `phpunit.xml`. |
| T22 | Tests só em SQLite; locks e SQL específico de MariaDB sem prova. | Confirmado | Rodar parte da suíte em MariaDB (container) no CI. |

### Hipóteses descartadas na leitura

- Rotas com ordem errada (`/tickets/export`, `/tickets/create`): estão antes de `/tickets/{ticket}`.
- Policies sem registro: usam a descoberta automática do Laravel (`App\Policies\XPolicy` para `App\Models\X`).
- Anexos servidos por URL pública: o disco `attachments` não tem `url` e o download passa por controller autorizado.
- Segredos versionados: `.env`, `.env.docker` e `helpdesk.sql` estão no `.gitignore` e fora da lista de arquivos rastreados.

## Observação do QA

`docs/relatorio-qa-helpdesk-pro.md` (QA parcial) traz a observação OBS-001: a tela de SLA mostra "Restam 2 min" em chamado pausado cuja violação já estava registrada. Não foi reproduzida nesta auditoria.
