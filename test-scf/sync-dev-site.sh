#!/usr/bin/env bash
# Copy the plugin into the running WordPress container.
#
# The dev site at http://localhost:8899 does NOT read this repository. Its
# plugin directory is a plain copy inside the container's WordPress volume, so
# a commit here changes nothing on the site — the screen keeps showing the old
# interface and it looks exactly like a feature that was never built.
#
# Run this after every change you want to see in the browser, then reload.
#
# Usage:  test-scf/sync-dev-site.sh
set -euo pipefail

WP=wp-env-test-scf-e54e6b03-wordpress-1
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST=/var/www/html/wp-content/plugins/sdc-page-builder
STAGE=/tmp/sdc-sync

if ! docker exec "$WP" test -d /var/www/html/wp-content/plugins; then
	echo "$WP is not running — start the site first (npx wp-env start)." >&2
	exit 2
fi

# Copy through a staging directory instead of into the live one: writing file by
# file would let the browser load a half-updated plugin, and a fatal in the
# middle of that is impossible to tell apart from a real bug.
docker exec "$WP" sh -lc "rm -rf $STAGE && mkdir -p $STAGE"
tar -C "$ROOT/plugin" -cf - . | docker exec -i "$WP" tar -C "$STAGE" -xf -
docker exec "$WP" sh -lc "rm -rf $DEST && mv $STAGE $DEST"

# Verify instead of assuming: a silent copy failure is the whole reason this
# script exists.
bad=0
while IFS= read -r file; do
	rel="${file#"$ROOT"/plugin/}"
	host_sum="$(md5sum "$file" | cut -d' ' -f1)"
	cont_sum="$(docker exec "$WP" md5sum "$DEST/$rel" 2>/dev/null | cut -d' ' -f1 || true)"

	if [ "$host_sum" != "$cont_sum" ]; then
		echo "ABWEICHUNG: $rel" >&2
		bad=1
	fi
done < <(find "$ROOT/plugin" -type f \( -name '*.php' -o -name '*.js' -o -name '*.css' \))

if [ "$bad" -ne 0 ]; then
	exit 1
fi

echo "Synchronisiert nach $WP:$DEST — jetzt im Browser neu laden."
