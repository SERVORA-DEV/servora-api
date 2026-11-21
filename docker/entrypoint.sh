#!/bin/sh
# Container start on Render. Everything here has to be idempotent — Render
# restarts this on every deploy, on a crash, and when a free-tier instance
# wakes back up from a spin-down.
set -e

# Render assigns the port via $PORT (10000 by default) and only routes to a
# server bound on 0.0.0.0. Apache's stock config listens on 80, so rewrite
# both the listener and the vhost before starting it.
: "${PORT:=10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" \
    /etc/apache2/sites-available/000-default.conf

# Framework caches. Built at boot rather than at image-build time because
# they bake in env vars (APP_URL, DB_*, CLOUDINARY_URL) that only exist as
# Render dashboard values at runtime. No view:cache — this is a JSON API
# with no Blade templates to compile.
php artisan config:cache
php artisan route:cache
php artisan event:cache

# migrate, never migrate:fresh. This runs against the live Supabase database
# on every single boot; anything destructive here would wipe production.
# Seeding is deliberately absent — run it once by hand from Render Shell.
php artisan migrate --force

# The scheduler (bootstrap/app.php withSchedule): booking and payment
# reminders, service-end alerts, closing past days, the app-update notice.
# Without it none of those ever run. It runs beside Apache for as long as the
# instance is up, as www-data so the cache files it writes stay writable by the
# web server, and restarts itself if it ever exits. Reminders missed while a
# free instance slept go out when it wakes (the reminder job looks 48 h ahead).
(
  while true; do
    su -s /bin/sh www-data -c "php /var/www/html/artisan schedule:work --no-interaction" >&2 || true
    sleep 5
  done
) &

exec apache2-foreground
