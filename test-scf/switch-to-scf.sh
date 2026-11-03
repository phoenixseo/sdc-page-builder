#!/bin/bash
# Zurück auf SCF, ACF Free aus dem Weg.
set -euo pipefail
W=wp-env-test-scf-e54e6b03-wordpress-1
CLI=wp-env-test-scf-e54e6b03-cli-1
PLUGINS=/var/www/html/wp-content/plugins

docker exec "$CLI" wp plugin deactivate advanced-custom-fields 2>&1 | tail -1
docker exec "$W" sh -c "rm -rf $PLUGINS/advanced-custom-fields" && echo "ACF entfernt"
docker exec "$CLI" wp plugin activate secure-custom-fields 2>&1 | tail -1
docker exec "$CLI" wp plugin list --format=csv --fields=name,status,version
