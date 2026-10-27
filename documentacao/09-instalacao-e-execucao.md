# Instalação e execução

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md).

**Como este capítulo foi validado.** Os comandos foram conferidos contra `composer.json`, `package.json`, `docker-compose.yml`, `docker/entrypoint.sh` e o `README.md` do projeto. Nesta análise foram **executados** apenas `php artisan test --compact`, `npm run build` e `php artisan route:list`. **Não** foram executados `composer install`, `npm install`, migrations, seeders nem Docker; a instalação do zero não foi reproduzida. Nenhum valor de variável de ambiente é registrado aqui.

## Requisitos

- PHP 8.2 ou superior (exigido em `composer.json`; ambiente local da análise: 8.2.4; imagem Docker: 8.3) com `pdo_mysql`, `mbstring`, `fileinfo`, `zip`, `gd`, `intl` e `bcmath`. A `fileinfo` é necessária à inspeção de anexos e a `zip` à validação de DOCX e XLSX.
- Composer 2.
- Node.js 20 ou superior e npm (imagem Docker: Node 22).
- MariaDB 10.6 ou superior (ou MySQL 8), segundo o README. Docker: `mariadb:11`.
- Para Docker, Docker Desktop com Compose.

## Instalação local (XAMPP, Windows)

Conforme o README e o `.env.example`:

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev        # ou: npm run build
```

Notas:

1. Crie antes o banco `helpdesk`. Os valores `DB_*` do `.env.example` apontam para `127.0.0.1:3306`, usuário `root`, sem senha.
2. A aplicação é acessada em `http://localhost/helpdesk/public` (ou o endereço configurado no Apache do XAMPP), segundo o README.
3. `migrate --seed` roda `RolesAndPermissionsSeeder`, `LocalSuperAdminSeeder` (só em ambiente `local`) e cria `test@example.com`, usuário de teste sem papel. **Troque a senha ou remova esse usuário antes de qualquer uso real.**
4. O `DatabaseSeeder` **não é idempotente**: rodá-lo duas vezes quebra no índice único de e-mail. `RolesAndPermissionsSeeder` e `LocalSuperAdminSeeder`, rodados isoladamente (`--class=`), são idempotentes. O README chama `php artisan db:seed` de idempotente, o que é impreciso (T28).
5. Para conceder o papel `superadmin` de teste, defina `SEED_SUPERADMIN_EMAIL` no `.env` e rode `php artisan db:seed --class=LocalSuperAdminSeeder` depois que o usuário existir.
6. O arquivo `public/hot`, se existir, faz a aplicação procurar os assets no servidor de desenvolvimento do Vite. Sem o `npm run dev` rodando, remova-o.
7. Anexos: o PHP recusa uploads acima de `upload_max_filesize` e `post_max_size` antes do Laravel ver a requisição. Os valores precisam ser maiores que os da aplicação (10 MB por arquivo e mais de 20 MB por envio). No Docker isso está em `docker/php.ini` (20M e 25M); no XAMPP, ajuste o `php.ini` e reinicie o Apache.

### Atalhos do Composer

`composer.json` define os scripts `setup`, `dev` e `test`:

| Script | O que faz (conforme `composer.json`) |
|---|---|
| `composer run setup` | `composer install`, cria `.env` se faltar, `key:generate`, `migrate --force`, `npm install` e `npm run build`. **Não roda seeders.** |
| `composer run dev` | Roda em paralelo `php artisan serve`, `queue:listen`, `pail` e `npm run dev`. Não foi executado; o `pail` depende de recursos do PHP que podem faltar no Windows, então prefira `npm run dev` separado. (**Não verificado.**) |
| `composer test` | `config:clear` e `php artisan test`. |

## Agendador (SLA)

O motor de SLA registra violações de chamados parados por `php artisan sla:record-breaches`, agendado a cada minuto em `routes/console.php`. É preciso agendar `php artisan schedule:run` a cada minuto (Agendador de Tarefas do Windows ou cron). Sem isso, as violações de chamados em que ninguém age depois do prazo não são registradas; as ações do usuário sobre o chamado continuam registrando as que já venceram. No Docker, o serviço `scheduler` faz isso.

## Docker

`docker-compose.yml` define:

| Serviço | Função |
|---|---|
| `app` | Apache e PHP 8.3 na porta 8080. O entrypoint espera o banco, roda `migrate --force` e, em produção, `config:cache`, `route:cache`, `view:cache` e `event:cache`. |
| `queue` | `queue:work`. Hoje sem trabalho, pois não há jobs. |
| `scheduler` | Laço que roda `schedule:run` a cada 60 s. |
| `db` | MariaDB 11 com volume persistente. |

Passos do README:

```bash
copy .env.docker.example .env.docker
docker compose run --rm --no-deps -e APP_KEY=base64:x app php artisan key:generate --show
docker compose up -d --build
docker compose exec app php artisan db:seed --force
```

O segundo comando imprime a `APP_KEY`, que deve ser colocada em `.env.docker`. A aplicação responde em `http://localhost:8080`.

Pontos de atenção:

- `docker-compose.override.yml` é carregado automaticamente e coloca `APP_ENV=local`, `APP_DEBUG=true`, monta o código-fonte e sobe um container Vite na porta 5173. **Para produção use `docker compose -f docker-compose.yml up -d`.**
- `.env.docker` é ignorado pelo Git e criado a partir de `.env.docker.example`.
- `db:seed --force` também cria `test@example.com`; em produção, remova esse usuário.

## Variáveis de ambiente relevantes (nomes, sem valores)

| Variável | Uso |
|---|---|
| `APP_*`, `APP_LOCALE=pt_BR` | Identidade, ambiente, idioma. `APP_URL` entra nos links de e-mail. |
| `DB_*` | Conexão com o banco. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` nos exemplos. |
| `MAIL_*` | Envio de e-mail (`log` no Docker de exemplo). |
| `SEED_SUPERADMIN_EMAIL` | Só em `local`. |
| `ATTACHMENTS_DISK`, `ATTACHMENTS_MAX_FILE_KB` (10 MB), `ATTACHMENTS_MAX_FILES` (5), `ATTACHMENTS_MAX_UPLOAD_KB` (20 MB), `ATTACHMENTS_MAX_FILES_PER_TICKET` (20), `ATTACHMENTS_MAX_TICKET_KB` (100 MB) | Limites de anexos (`config/attachments.php`). |

Os anexos ficam em `storage/app/private/attachments`, fora de `public/`. O README recomenda fazer backup do banco e dessa pasta juntos.

## Comandos úteis

```bash
composer test                       # limpa o config e roda a suíte
php artisan test --compact
npm run build                       # vue-tsc + vite build
php artisan sla:record-breaches     # roda o motor de SLA manualmente
php artisan db:seed --class=RolesAndPermissionsSeeder   # idempotente
```

## Lacunas deste capítulo

- A instalação do zero (XAMPP e Docker) não foi reproduzida nesta análise.
- Não há documentação verificada de implantação em produção (HTTPS, servidor web, backup, rotação de logs); o README é o único registro e não foi exercitado.
- O README menciona e-mails de "verificação de conta" e a necessidade de um worker de fila; no código não há e-mail de verificação enviado nem jobs enfileirados (T28).
