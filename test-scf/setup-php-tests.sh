#!/usr/bin/env bash
# One-time (per container) setup for the PHP suite.
#
# Installs composer dev tooling (PHPUnit, WPCS, WordPress core, the WP test
# library) inside the wp-env container and stages ACF Free 6.8.10 so the suite
# runs against a real host plugin. Idempotent: skips what is already there.
#
# Needs network access to packagist and to wp.org (for ACF Free).
set -euo pipefail

CLI=wp-env-test-scf-e54e6b03-cli-1
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEV=/tmp/sdc-dev
ACF_VERSION=6.8.10

docker exec "$CLI" sh -lc "
	set -e
	mkdir -p $DEV $DEV/plugin
	# The test database is dropped and rebuilt on every run; it must never be
	# the site database.
	mariadb -hmysql -uroot -p\"\${WORDPRESS_DB_PASSWORD}\" \
		-e 'CREATE DATABASE IF NOT EXISTS wp_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
"

docker cp "$ROOT/plugin/composer.json" "$CLI:$DEV/plugin/composer.json"

docker exec -w "$DEV/plugin" "$CLI" sh -lc '
	set -e
	if [ ! -x vendor/bin/phpunit ]; then
		COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist
	fi
	' 2>&1 | tail -5

# ACF Free: fetch once, keep an unpacked copy for every later run.
docker exec -w /var/www/html/wp-content/plugins "$CLI" sh -lc "
	set -e
	if [ ! -f /tmp/acf-free/advanced-custom-fields/acf.php ]; then
		mkdir -p /tmp/acf-free
		wp plugin install advanced-custom-fields --version=$ACF_VERSION --allow-root >/dev/null
		cp -r /var/www/html/wp-content/plugins/advanced-custom-fields /tmp/acf-free/
	fi
"

docker exec "$CLI" sh -lc 'echo "acf: $(grep -m1 "Version:" /tmp/acf-free/advanced-custom-fields/acf.php | tr -s " ")"; echo "phpunit: $(/tmp/sdc-dev/plugin/vendor/bin/phpunit --version)"'
