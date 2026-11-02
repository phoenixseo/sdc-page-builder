#!/bin/bash
set -euo pipefail
B=/home/hermes/hermes_agent/workspaces/phoenixseo/sdc-page-builder
cd "$B"

git add -A
git -c user.name='PHOENIXSEO' -c user.email='dev@phoenixseo.de' commit -q -F - <<'MSG'
M2-Renderer: Listen baut der Builder, Blaetter liefert der Wirt

ACF Free hat keinen repeater-Feldtyp. Gemessen: acf_render_field() auf ein
repeater-Feld liefert 0 Bytes und keine Fehlermeldung — das Feld verschwindet
still. Ein Design, das Listen beim Wirt rendert, laeuft auf SCF und faellt auf
ACF Free lautlos aus.

Deshalb: Container (list/group) rendert der Builder selbst, Blaetter kommen
vom Wirt. Nachweis: identische HTML-Signatur 259582a6... in ACF Free 6.8.10
und SCF 6.9.7 (4190 Bytes, 13 Eingaben, gleiche Namen, gleiche Verschachtelung).

- includes/class-sdc-renderer.php: render_component/render_fields/render_list
- Namensbau ueber prefix, weil acf_prepare_field() name=key erzwingt
- tests/render-verify.php: 15 Pruefungen, Template-Zeile mitgezaehlt
- test-scf/switch-to-acf-free.sh und switch-to-scf.sh: Wirt tauschen ohne Stack-Neubau
MSG

git push -q origin main
git log --oneline -3
echo "--- sync ---"
git status -sb | head -2
