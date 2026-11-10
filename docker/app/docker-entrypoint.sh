#!/bin/sh
set -e

# The containers run as an unprivileged user, which needs to be able to write to every volume.
# Fail early with a clear message instead of failing on the first write somewhere in the app.
for dir in /var/www/build /var/www/storage/database /var/www/storage/files /var/www/watch /var/www/backups; do
    if [ -d "$dir" ] && [ ! -w "$dir" ]; then
        echo "Dreeve runs as user $(id -u):$(id -g), but cannot write to $dir." >&2
        echo "Give that user the volume, for example with: chown -R $(id -u):$(id -g) <path on the host>" >&2
        exit 1
    fi
done

# The app and daemon containers share the same image and the same SQLite database
# volume, so only one of them may migrate. The web container is the migrator, every other
# container waits for it to finish before touching the database.
if [ "$1" = "frankenphp" ]; then
    php /var/www/bin/console app:db:migrate --no-interaction
    php /var/www/bin/console app:cache:render:clear --with-version-check
else
    echo 'Waiting for the database schema to be up to date...'
    php /var/www/bin/console app:db:wait-for-schema
    echo 'Database schema updated, starting daemon'
fi

exec "$@"