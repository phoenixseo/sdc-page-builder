#!/bin/bash
# Zurueck auf SCF, ACF Free aus dem Weg.
#
# SCF liegt als Bind-Mount im WordPress-Ordner und wird deshalb nur
# aktiviert, nie verschoben. ACF Free liegt im normalen plugins-Ordner und
# wird entfernt, damit beim naechsten ACF-Lauf eine frische Fassung aus
# wp.org kommt statt einer halb deinstallierten.
set -euo pipefail

W=wp-env-test-scf-e54e6b03-wordpress-1
CLI=wp-env-test-scf-e54e6b03-cli-1
PLUGINS=/var/www/html/wp-content/plugins

echo "== 1. ACF deaktivieren und entfernen =="
docker exec "$CLI" wp plugin deactivate advanced-custom-fields 2>&1 | tail -1 || true
docker exec "$W" sh -c "rm -rf $PLUGINS/advanced-custom-fields" && echo "ACF entfernt"

echo "== 2. SCF aktivieren =="
docker exec "$CLI" wp plugin activate secure-custom-fields 2>&1 | tail -1

echo "== 3. Zustand =="
docker exec "$CLI" wp plugin list --format=csv --fields=name,status,version
