#!/bin/bash
# Baut den *Dev*-Stack (wp-env) auf ACF Free um, ohne den Stack neu zu erzeugen.
#
# Zwei getrennte Installationen, nicht eine:
#   - Dev-Stack:  /var/www/html/wp-content/plugins/ im wordpress-Container.
#                 Dort greift dieses Skript, fürs Klicken im Backend.
#   - Test-Suite: eine eigene Installation unter /tmp/sdc-dev/plugin/wordpress,
#                 die tests/phpunit/bootstrap.php selbst verlinkt. Die Suite
#                 liest ACF aus SDC_TEST_ACF_ZIP (phpunit.xml.dist, Standard
#                 /tmp/acf-free/advanced-custom-fields) und ist von diesem
#                 Skript NICHT betroffen. Für den Host-Vergleich genügt:
#                   SDC_TEST_HOST_PLUGIN=acf test-scf/run-php-tests.sh
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

echo "== 3. Zustand Dev-Stack =="
docker exec "$CLI" wp plugin list --format=csv --fields=name,status,version

echo "== 4. Was die Suite benutzt (von diesem Skript nicht gesteuert) =="
echo "   Die Suite ist auf ACF 6.8.10 festgelegt (phpunit.xml.dist, wie in CI);"
echo "   der Dev-Stack bekommt dagegen die aktuelle Version von wp.org."
docker exec "$CLI" sh -lc 'grep -m1 "Version:" /tmp/acf-free/advanced-custom-fields/acf.php 2>/dev/null || echo "kein ACF unter /tmp/acf-free/advanced-custom-fields - die Suite laeuft so nicht auf ACF"'
