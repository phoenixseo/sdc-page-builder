#!/usr/bin/env bash
# Run the PHP suite inside the wp-env container, against the repository tree.
#
# The container has no bind-mount of the repo, so this mirrors plugin/ there and
# runs phpunit in it. The previous mirror is removed first: a test file that no
# longer exists in the repo must not keep running in the container.
#
# Vendor, WordPress core and ACF Free are installed once per container by
# test-scf/setup-php-tests.sh; this script never touches wp.org.
#
# Usage:  test-scf/run-php-tests.sh [phpunit args...]
#         SDC_TEST_HOST_PLUGIN=scf test-scf/run-php-tests.sh
#         SDC_TEST_CORE=/tmp/sdc-wp64/wordpress \
#         SDC_TEST_LIB=/tmp/sdc-wp64/vendor/wp-phpunit/wp-phpunit \
#             test-scf/run-php-tests.sh
#
# SDC_TEST_CORE and SDC_TEST_LIB point the suite at a second toolchain, so the
# minimum WordPress version in the plugin header can be *run* instead of only
# asserted. Both are absolute paths inside the container; unset means the
# vendored core in /tmp/sdc-dev is used. WordPress ships deprecations between
# majors, so the core the run used is printed — a minimum-version run that
# silently fell back to the vendored core would prove nothing.
#
# Exits with phpunit's exit code.
set -euo pipefail

CLI=wp-env-test-scf-e54e6b03-cli-1
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEV=/tmp/sdc-dev
HOST="${SDC_TEST_HOST_PLUGIN:-acf}"
CORE="${SDC_TEST_CORE:-}"
LIB="${SDC_TEST_LIB:-}"

EXTRA_ENV=()
if [ -n "$CORE" ]; then
	if ! docker exec "$CLI" test -f "$CORE/wp-includes/version.php"; then
		echo "SDC_TEST_CORE=$CORE is not a WordPress checkout in $CLI." >&2
		exit 2
	fi
	EXTRA_ENV+=(-e "SDC_TEST_WP_DIR=$CORE")
	echo "core: $(docker exec "$CLI" sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "$CORE/wp-includes/version.php") ($CORE)"
fi
if [ -n "$LIB" ]; then
	if ! docker exec "$CLI" test -f "$LIB/includes/bootstrap.php"; then
		echo "SDC_TEST_LIB=$LIB is not a wp-phpunit library in $CLI." >&2
		exit 2
	fi
	EXTRA_ENV+=(-e "WP_TESTS_DIR=$LIB")
fi

# The password lives in the container's own environment; read it there instead
# of requiring the caller to export it.
DB_PASSWORD="$(docker exec "$CLI" printenv WORDPRESS_DB_PASSWORD)"

if ! docker exec "$CLI" test -x "$DEV/plugin/vendor/bin/phpunit"; then
	echo "No test tooling in $CLI:$DEV/plugin — run test-scf/setup-php-tests.sh first." >&2
	exit 2
fi

# Mirror the repository tree; vendor/, wordpress/ and node_modules/ live in the
# container and must survive.
docker exec "$CLI" sh -lc "
	set -e
	cd $DEV/plugin
	find . -mindepth 1 -maxdepth 1 \
		! -name vendor ! -name wordpress ! -name node_modules \
		-exec rm -rf {} +
	cd $DEV
	cp -a composer.json composer.lock vendor wordpress plugin/
"

docker cp "$ROOT/plugin/." "$CLI:$DEV/plugin/"

docker exec -w "$DEV/plugin" \
	-e SDC_TEST_HOST_PLUGIN="$HOST" \
	-e SDC_TEST_DB_PASSWORD="$DB_PASSWORD" \
	"${EXTRA_ENV[@]}" \
	"$CLI" sh -lc 'exec vendor/bin/phpunit "$@"' phpunit "$@"
