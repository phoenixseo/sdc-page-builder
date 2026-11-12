#!/usr/bin/env bash
# Build a second toolchain for the minimum WordPress version the plugin declares.
#
# readme.txt and the plugin header promise "Requires at least: 6.4". That is
# only worth something if a run exists on that core, so this stages WordPress
# 6.4 plus its matching test library in the container, next to the vendored
# current core that test-scf/setup-php-tests.sh installed.
#
# The version is read from the plugin header — not typed here — so raising the
# minimum moves this toolchain with it instead of silently leaving an old one.
#
# Idempotent: skips what is already staged. Needs packagist only.
#
# Usage:
#   test-scf/setup-wp-min.sh
#   SDC_TEST_CORE=/tmp/sdc-wpmin/wordpress \
#   SDC_TEST_LIB=/tmp/sdc-wpmin/vendor/wp-phpunit/wp-phpunit \
#       test-scf/run-php-tests.sh
set -euo pipefail

CLI=wp-env-test-scf-e54e6b03-cli-1
DEV=/tmp/sdc-wpmin
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

MIN="$(sed -n 's/^ \* Requires at least: *//p' "$ROOT/plugin/sdc-page-builder.php" | head -1)"
if [ -z "$MIN" ]; then
	echo "No 'Requires at least' in the plugin header." >&2
	exit 2
fi

# "6.4" names a branch, not a release: composer needs 6.4.*. A pinned patch
# version ("6.4.3") is used as written.
if printf '%s' "$MIN" | grep -Eq '^[0-9]+\.[0-9]+$'; then
	CONSTRAINT="$MIN.*"
else
	CONSTRAINT="$MIN"
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

cat > "$TMP/composer.json" <<JSON
{
	"description": "Toolchain for the plugin's declared minimum WordPress version.",
	"require-dev": {
		"phpunit/phpunit": "^9.6",
		"yoast/phpunit-polyfills": "^2.0",
		"roots/wordpress": "$CONSTRAINT",
		"wp-phpunit/wp-phpunit": "$CONSTRAINT"
	},
	"config": {
		"allow-plugins": {
			"roots/wordpress-core-installer": true
		}
	}
}
JSON

docker exec "$CLI" sh -lc "mkdir -p $DEV"
docker cp "$TMP/composer.json" "$CLI:$DEV/composer.json"

# A toolchain for a different minimum has a different lock and a different core:
# wipe the staged core and library, keep vendor/ so a repeat run is cheap.
docker exec "$CLI" sh -lc "rm -rf $DEV/wordpress $DEV/vendor/wp-phpunit $DEV/composer.lock"

docker exec -w "$DEV" "$CLI" sh -lc \
	'COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-progress 2>&1 | tail -5' \
	2>&1 | tail -5

STAGED="$(docker exec "$CLI" sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "$DEV/wordpress/wp-includes/version.php")"

if [ -z "$STAGED" ]; then
	echo "Toolchain staged no core in $CLI:$DEV/wordpress." >&2
	exit 1
fi

echo "Toolchain: WordPress $STAGED (header asks for $MIN) in $CLI:$DEV"
echo "Run the suite with:"
echo "  SDC_TEST_CORE=$DEV/wordpress SDC_TEST_LIB=$DEV/vendor/wp-phpunit/wp-phpunit test-scf/run-php-tests.sh"
