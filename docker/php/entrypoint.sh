#!/bin/sh
set -e

# Install PHP dependencies into the (possibly empty) vendor volume on first boot.
if [ ! -f vendor/autoload.php ]; then
    echo "vendor/ missing — running composer install…"
    composer install --no-interaction --prefer-dist --no-progress
fi

exec "$@"
