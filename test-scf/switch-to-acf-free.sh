#!/bin/bash
# Baut den Teststack auf ACF Free um, ohne den Stack neu zu erzeugen.
#
# SCF liegt als Bind-Mount im WordPress-Ordner (/home/hermes/wp-env/...):
# ein `mv` darauf scheitert mit "Device or resource busy". Deshalb wird es
# nur deaktiviert, nicht weggeschoben - zwei aktive Feld-Plugins kann es
# ohnehin nicht geben, weil beide ACF_VERSION definieren.
#
# ACF Free kommt aus dem wp.org-Repo in den Container, nicht per `docker cp`
# aus dem Scratch-Cache: der wird nach 24 h geraeumt, dann scheitert der
# Aufbau an einem fehlenden Verzeichnis statt an der Sache.
set -euo pipefail

CLI=wp-env-test-scf-e54e6b03-cli-1

echo "== 1. SCF deaktivieren =="
docker exec "$CLI" wp plugin deactivate secure-custom-fields 2>&1 | tail -2

echo "== 2. ACF Free installieren und aktivieren =="
if docker exec "$CLI" wp plugin is-installed advanced-custom-fields >/dev/null 2>&1; then
	docker exec "$CLI" wp plugin activate advanced-custom-fields 2>&1 | tail -2
else
	docker exec "$CLI" wp plugin install advanced-custom-fields --activate 2>&1 | tail -2
fi

echo "== 3. Zustand =="
docker exec "$CLI" wp plugin list --format=csv --fields=name,status,version
