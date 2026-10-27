# Stack e execução

## Versões

Obtidas de `composer.lock` e dos `package.json` em `node_modules`:

| Componente | Versão |
|---|---|
| PHP | exigido `^8.2`; ambiente local 8.2.4; imagem Docker 8.3 |
| Laravel | 12.70.0 |
| Inertia (Laravel / Vue) | 2.0.28 / 2.3.29 |
| Ziggy | 2.6.5 |
| Pest / PHPUnit | 3.8.7 / 11.5.56 |
| Pint | 1.30.4 |
| Vue / TypeScript / vue-tsc | 3.5.44 / 5.9.3 / 2.2.12 |
| Vite / laravel-vite-plugin | 7.3.7 / 2.1.0 |
| Tailwind CSS | 3.4.19 (com `@tailwindcss/forms`) |
| Axios | 1.20.0 |
| Banco | MariaDB (Docker: `mariadb:11`); testes em SQLite em memória |
| Node (Docker) | 22 |

Observações:

- `laravel/sanctum` está em `require`, mas nada o usa (sem `HasApiTokens`, sem rotas de API).
- `package.json` declara `@tailwindcss/vite ^4`, que o `vite.config.js` não usa; o estilo roda com Tailwind 3 via PostCSS.
- Não há CI, ESLint, Prettier nem PHPStan. O Pint está instalado, sem `pint.json`.

## Requisitos locais

PHP 8.2+ com `pdo_mysql`, `mbstring`, `fileinfo`, `zip`, `gd`, `intl` e `bcmath`; Composer 2; Node 20+; MariaDB 10.6+ (ou MySQL 8). A extensão `fileinfo` é necessária para a inspeção de anexos, e `zip` para validar DOCX e XLSX.

## Instalação local (XAMPP, Windows)

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev        # ou: npm run build
```

Notas:

- Crie antes o banco `helpdesk`. Os valores `DB_*` do `.env.example` apontam para `127.0.0.1:3306`, usuário `root`, sem senha.
- `migrate --seed` roda `RolesAndPermissionsSeeder`, `LocalSuperAdminSeeder` (só em ambiente `local`) e cria `test@example.com` (senha da factory, sem papel). **Troque ou remova esse usuário antes de qualquer uso real.**
- O `DatabaseSeeder` não é idempotente: rodá-lo duas vezes quebra no índice único de e-mail. Já `RolesAndPermissionsSeeder` e `LocalSuperAdminSeeder` são idempotentes.
- Para conceder o papel `superadmin` de teste, defina `SEED_SUPERADMIN_EMAIL` no `.env` e rode `php artisan db:seed --class=LocalSuperAdminSeeder` depois que o usuário existir.
- O arquivo `public/hot`, se existir, faz a aplicação procurar os assets no servidor de desenvolvimento do Vite. Sem o `npm run dev` rodando, remova-o.

## Agendador (SLA)

O motor de SLA registra violações de chamados parados por `php artisan sla:record-breaches`, chamado a cada minuto pelo scheduler. É preciso agendar `php artisan schedule:run` a cada minuto (Agendador de Tarefas do Windows ou cron). Sem isso, as violações de chamados em que ninguém age depois do prazo não são registradas; as ações do usuário sobre o chamado continuam registrando as que já venceram.

## Docker

`docker-compose.yml` define:

| Serviço | Função |
|---|---|
| `app` | Apache e PHP 8.3 na porta 8080. O entrypoint espera o banco, roda `migrate --force` e, em produção, `config:cache`, `route:cache`, `view:cache` e `event:cache`. |
| `queue` | `queue:work`. Hoje sem trabalho, pois não há jobs. |
| `scheduler` | Laço que roda `schedule:run` a cada 60 s. |
| `db` | MariaDB 11 com volume persistente. |

Pontos de atenção:

- `docker-compose.override.yml` é carregado automaticamente e coloca `APP_ENV=local`, `APP_DEBUG=true`, monta o código-fonte e sobe um container Vite na porta 5173. **Para produção use `docker compose -f docker-compose.yml up -d`.**
- `.env.docker` (ignorado pelo Git) é criado a partir de `.env.docker.example`. A `APP_KEY` precisa ser gerada.
- O README orienta rodar `docker compose exec app php artisan db:seed --force` na primeira vez. Isso também cria `test@example.com`; em produção, remova esse usuário.
- `docker/php.ini` define `upload_max_filesize=20M` e `post_max_size=25M`, compatíveis com os limites de anexos.

## Variáveis de ambiente relevantes (nomes, sem valores)

| Variável | Uso |
|---|---|
| `APP_*`, `APP_LOCALE=pt_BR` | Identidade, ambiente e idioma. |
| `DB_*` | Conexão MariaDB. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` nos exemplos. |
| `MAIL_*` | Envio de e-mail (`log` no Docker de exemplo). |
| `SEED_SUPERADMIN_EMAIL` | Só em `local`. |
| `ATTACHMENTS_DISK`, `ATTACHMENTS_MAX_FILE_KB` (10 MB), `ATTACHMENTS_MAX_FILES` (5), `ATTACHMENTS_MAX_UPLOAD_KB` (20 MB), `ATTACHMENTS_MAX_FILES_PER_TICKET` (20), `ATTACHMENTS_MAX_TICKET_KB` (100 MB) | Limites de anexos (`config/attachments.php`). |

## Comandos úteis

```bash
composer test                       # limpa o config e roda a suíte
php artisan test --compact
npm run build                       # vue-tsc + vite build
php artisan sla:record-breaches     # roda o motor de SLA manualmente
php artisan db:seed --class=RolesAndPermissionsSeeder   # idempotente
```

## Verificações feitas em 27/10/2026

- `php artisan test --compact`: 1.486 aprovados, 0 falhas, 9.596 asserções, 122,78 s.
- `npm run build`: `vue-tsc` sem erros e Vite concluído em 11,52 s (maior chunk: `app` com 314 kB, 109,7 kB com gzip).
- A suíte escreve no `storage/logs/laravel.log` real (duas entradas de falhas simuladas de propósito).
- A execução em MariaDB não foi feita nesta auditoria.
