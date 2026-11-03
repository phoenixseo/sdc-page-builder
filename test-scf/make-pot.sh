#!/usr/bin/env bash
# Regenerate the translation template from the plugin sources.
#
# Used from the repo root. Requires WP-CLI with the i18n command, either on the
# host or inside the wp-env CLI container.
#
# Three flags are load-bearing, each for a measured reason:
#   --slug      without it the header becomes
#               "Report-Msgid-Bugs-To: .../support/plugin/plugin" (the last path
#               segment is used twice), which points at the wrong support page.
#   --exclude   vendor/ and wordpress/ make peast exhaust the default 128M and
#               abort with a fatal error; tests/ carries no translatable strings.
#   memory_limit  the remaining core scan still needs more than the CLI default.
#
# Output is deterministic: a re-run over unchanged sources reproduces the
# committed file byte for byte apart from the POT-Creation-Date header.
set -euo pipefail

PLUGIN_DIR="${1:-plugin}"
CLI="${SDC_TEST_CLI:-wp-env-test-scf-e54e6b03-cli-1}"

docker cp "$PLUGIN_DIR/." "$CLI:/tmp/sdc-pot-src/"
docker exec "$CLI" bash -c '
	set -e
	cd /tmp/sdc-pot-src
	php -d memory_limit=1G /usr/local/bin/wp i18n make-pot . languages/sdc-page-builder.pot \
		--domain=sdc-page-builder \
		--slug=sdc-page-builder \
		--exclude=vendor,node_modules,wordpress,tests \
		--allow-root
'
docker cp "$CLI:/tmp/sdc-pot-src/languages/sdc-page-builder.pot" "$PLUGIN_DIR/languages/sdc-page-builder.pot"

echo "POT geschrieben nach $PLUGIN_DIR/languages/sdc-page-builder.pot"
grep -c '^msgid ' "$PLUGIN_DIR/languages/sdc-page-builder.pot" | sed 's/^/msgids: /'
