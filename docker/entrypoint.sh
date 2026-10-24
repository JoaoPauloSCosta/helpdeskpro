#!/bin/sh
set -e

cd /var/www/html

# Só o container web prepara a aplicação; worker e scheduler reaproveitam a imagem.
if [ "$1" = "apache2-foreground" ]; then
    if [ -z "$APP_KEY" ]; then
        echo "ERRO: APP_KEY não definido. Gere com: docker compose run --rm app php artisan key:generate --show" >&2
        exit 1
    fi

    echo "Aguardando o banco em ${DB_HOST}:${DB_PORT:-3306}..."
    until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
        sleep 2
    done

    php artisan storage:link --force >/dev/null 2>&1 || true
    php artisan migrate --force
    # Em produção os caches aceleram a aplicação; fora dela, são limpos para
    # que o código editado apareça na hora.
    if [ "$APP_ENV" = "production" ]; then
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        php artisan event:cache
    else
        php artisan optimize:clear
    fi

    chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
