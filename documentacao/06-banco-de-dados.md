# Banco de dados

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md).

**Aviso importante.** Este capítulo descreve o **esquema definido nas migrations** (`database/migrations`, 18 arquivos, 21 tabelas) e nos models (`app/Models`). Ele **não** descreve o banco atualmente implantado em nenhum ambiente: nenhuma migration foi executada, nenhum banco foi consultado, e o dump `helpdesk.sql` (que o `.gitignore` descreve como dados reais) não foi aberto. A suíte de testes usa SQLite em memória; o alvo de execução é MariaDB.

## Diagrama

```mermaid
erDiagram
    ROLES ||--o{ USERS : "role_id"
    DEPARTMENTS ||--o{ USERS : "department_id"
    ROLES ||--o{ ROLE_PERMISSIONS : ""
    PERMISSIONS ||--o{ ROLE_PERMISSIONS : ""
    USERS ||--o{ TICKETS : "requester_id"
    USERS |o--o{ TICKETS : "assigned_to"
    DEPARTMENTS ||--o{ TICKETS : ""
    TICKET_CATEGORIES ||--o{ TICKETS : "category_id"
    SLA_POLICIES |o--o{ TICKETS : "sla_policy_id"
    TICKETS ||--o{ TICKET_COMMENTS : ""
    TICKET_COMMENTS ||--o{ TICKET_COMMENT_VERSIONS : ""
    TICKETS ||--o{ TICKET_ATTACHMENTS : ""
    TICKET_COMMENTS |o--o{ TICKET_ATTACHMENTS : "opcional"
    TICKETS ||--o{ TICKET_HISTORIES : ""
    TICKETS ||--o{ TICKET_SLA_PAUSES : ""
    TICKET_HISTORIES |o--o| TICKET_SLA_PAUSES : "ticket_history_id"
    USERS ||--o{ AUDIT_LOGS : "user_id"
```

`audit_logs` se liga ao registro auditado por relação polimórfica (`auditable_type`, `auditable_id`): usuário, departamento, categoria, papel e política de SLA.

## Tabelas de domínio

| Tabela | Colunas principais | Observações |
|---|---|---|
| `users` | `name`, `email` (único), `password`, `role_id`, `department_id`, `is_active` | `role_id` e `department_id` anuláveis (`restrictOnDelete`). Sem papel, não há permissões. Não são atribuíveis em massa. |
| `roles` | `name`, `slug` (único), `description`, `is_system` | |
| `permissions` | `name`, `slug` (único), `description` | O módulo é o prefixo do `slug` (`tickets.`, `audit.`...). |
| `role_permissions` | `role_id`, `permission_id`, `created_at` | Único (`role_id`, `permission_id`). **Única tabela com `cascadeOnDelete`.** |
| `departments`, `ticket_categories` | `name`, `description`, `is_active` | Inativação no lugar de exclusão. |
| `tickets` | `ticket_number` (único, 11 caracteres), `title`, `description`, `category_id`, `requester_id`, `department_id`, `assigned_to`, `priority`, `status`, `first_response_at`, `resolved_at`, `closed_at`, `resolution_summary` | Índices em `priority`, `status`, `created_at`, `updated_at`. `priority` e `status` guardam o valor do enum. |
| colunas de SLA em `tickets` | `sla_policy_id`, `sla_first_response_minutes`, `sla_resolution_minutes`, `first_response_due_at`, `resolution_due_at`, `first_response_breached_at`, `resolution_breached_at`, `resolution_effective_seconds` | Metas copiadas da política na abertura ou na troca de prioridade. Todas anuláveis. |
| `ticket_comments` | `ticket_id`, `user_id`, `body`, `visibility` (`public`/`internal`) | Índice (`ticket_id`, `created_at`). Sem exclusão pelo fluxo normal. |
| `ticket_comment_versions` | `ticket_comment_id`, `edited_by`, `previous_body`, `previous_visibility`, `edited_at`, `created_at` | Somente-inserção. |
| `ticket_attachments` | `ticket_id`, `ticket_comment_id` (anulável), `uploaded_by`, `original_name`, `storage_path` (único), `mime_type`, `size_bytes`, `sha256` | Sem coluna de visibilidade: herdada da mensagem. `storage_path` e `sha256` ficam ocultos na serialização. |
| `ticket_histories` | `ticket_id`, `user_id` (anulável), `event_type`, `old_values` (JSON), `new_values` (JSON), `created_at` | Somente-inserção. `user_id` nulo para eventos do sistema (`sla_breached`). 17 tipos em `TicketEvent`. |
| `ticket_sla_pauses` | `ticket_id`, `ticket_history_id`, `pause_status`, `reason`, `supplier_name`, `protocol_number`, `related_department_id`, `responsible_name`, `expected_return_at`, `started_at`, `ended_at`, `duration_seconds`, `created_by` | Coluna gerada `active_ticket_id` com índice único: **no máximo uma pausa aberta por chamado**. |
| `sla_policies` | `priority`, `first_response_minutes`, `resolution_minutes`, `is_active` | Coluna virtual `active_priority` com índice único: **uma política ativa por prioridade**. |
| `audit_logs` | `user_id`, `event_type`, `auditable_type`, `auditable_id`, `old_values`, `new_values`, `created_at` | Somente-inserção. 19 tipos em `AdminEvent`. |

## Tabelas de infraestrutura Laravel

`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`. Nos exemplos de ambiente, sessão, cache e fila usam o banco.

## Migrations em ordem

| Migration | O que faz |
|---|---|
| `0001_01_01_000000_create_users_table` (+ `_000001_create_cache_table`, `_000002_create_jobs_table`) | Base do Laravel: usuários, redefinição de senha, sessões, cache, jobs. |
| `2026_10_21_000001_create_roles_and_permissions_tables` | `roles`, `permissions`, `role_permissions`. |
| `2026_10_21_000002_create_departments_and_ticket_categories_tables` | Departamentos e categorias. |
| `2026_10_21_000003_add_role_department_and_status_to_users_table` | `role_id`, `department_id` e `is_active` em `users`. |
| `2026_10_21_000004_create_tickets_table` | Chamados. |
| `2026_10_21_000005_create_ticket_comments_table`, `_000006_create_ticket_histories_table`, `_000007_create_ticket_sla_pauses_table`, `_000008_create_ticket_comment_versions_table` | Comentários, histórico, pausas de SLA, versões de comentário. |
| `2026_10_22_000001_create_audit_logs_table`, `_000002_create_sla_policies_table`, `_000003_add_active_priority_unique_to_sla_policies_table` | Auditoria, políticas de SLA e a unicidade da política ativa. |
| `2026_10_23_000001_add_sla_columns_to_tickets_table` | Colunas de SLA em `tickets`. |
| `2026_10_24_000001_grant_audit_view_to_technician_role`, `_000003_add_performance_report_permission` | **Migrations de dados**, com `insertOrIgnore`, para bancos já existentes. |
| `2026_10_24_000002_create_ticket_attachments_table` | Anexos. |

## Convenções e integridade

- **Preservação do histórico (RN-19):** todas as chaves estrangeiras de domínio são `restrictOnDelete`. Nada é apagado pelo fluxo normal; a inativação (`is_active`) substitui a exclusão.
- **Efeito colateral conhecido (inferido, não executado):** `ProfileController::destroy` faz exclusão real do usuário. Para quem tem chamados, comentários ou eventos, a restrição das chaves deve impedi-la com erro de integridade (T3).
- **Unicidade garantida no banco, não só na validação:** número do chamado, política ativa por prioridade, pausa ativa por chamado, caminho de anexo, par papel-permissão e e-mail do usuário.
- **Colunas geradas** (`storedAs`, `virtualAs`) funcionam em MariaDB e SQLite, mas só foram exercitadas em SQLite pela suíte.
- **JSON:** `old_values` e `new_values` guardam também rótulos (por exemplo, o nome do status) no momento do evento, para o histórico não depender do cadastro atual.
- **Metas copiadas:** editar uma política de SLA não reescreve chamados existentes.
- **Fuso:** instantes em UTC (`config/app.php`).

## Dados iniciais (seeders)

| Seeder | O que cria |
|---|---|
| `RolesAndPermissionsSeeder` | 20 permissões e 4 papéis; associação inicial só para papéis recém-criados. Idempotente. |
| `LocalSuperAdminSeeder` | Papel `superadmin` com todas as permissões, apenas em ambiente `local`; atribui ao e-mail de `SEED_SUPERADMIN_EMAIL`. Idempotente. |
| `DatabaseSeeder` | Chama os dois anteriores e cria `test@example.com` via factory, sem papel. **Não idempotente** (T6). |

Factories em `database/factories`: `User`, `Department`, `TicketCategory`, `Ticket`, `TicketComment`, `SlaPolicy`.

## Não verificado

- Planos de execução e desempenho dos índices em MariaDB.
- Conteúdo, versão de esquema e permissões do banco de qualquer instalação real (inclusive o estado da tabela `migrations`).
- Comportamento de `lockForUpdate` e das colunas geradas em MariaDB (a suíte roda em SQLite).
