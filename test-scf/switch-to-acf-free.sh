#!/bin/bash
# Baut den Teststack auf ACF Free um, ohne den Stack neu zu erzeugen.
# Sichert vorher den Zustand, damit er zurückgedreht werden kann.
set -euo pipefail

W=wp-env-test-scf-e54e6b03-wordpress-1
CLI=wp-env-test-scf-e54e6b03-cli-1
PLUGINS=/var/www/html/wp-content/plugins

echo "== 1. SCF deaktivieren =="
docker exec "$CLI" wp plugin deactivate secure-custom-fields 2>&1 | tail -2

echo "== 2. SCF wegnehmen (bleibt als Ordner .scf-bak) =="
docker exec "$W" sh -c "mv $PLUGINS/secure-custom-fields $PLUGINS/.scf-bak" && echo "verschoben"

echo "== 3. ACF Free 6.8.10 installieren =="
docker cp /home/hermes/.hermes/cache/scratch/fieldplugins/advanced-custom-fields "$W:$PLUGINS/advanced-custom-fields"
docker exec "$W" sh -c "chown -R www-data:www-data $PLUGINS/advanced-custom-fields"
docker exec "$CLI" wp plugin activate advanced-custom-fields 2>&1 | tail -2

echo "== 4. Zustand =="
docker exec "$CLI" wp plugin list --format=csv --fields=name,status,version
