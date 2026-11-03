# K7 — Release-Reife: Bericht

Stand: 2026-11-03 · Commit `afa8944` + `7dae92a` · kein Push (`main` ist 7 Commits vor `origin/main`)

## Nachprüfung (zweiter Durchlauf, alle Zahlen frisch gemessen)

Der erste Durchlauf starb an einer Gateway-Störung, die Arbeit lag aber committet im Baum. Dieser
Durchlauf hat **jede** Abnahmebedingung neu ausgeführt statt den Commits zu glauben:

| Punkt | Beleg (frisch) |
|---|---|
| PHPUnit, ein Befehl | `test-scf/run-php-tests.sh` → `OK (62 tests, 540 assertions)`, exit 0 |
| PHPUnit auf SCF | `SDC_TEST_HOST_PLUGIN=scf test-scf/run-php-tests.sh` → `OK (62 tests, 540 assertions)`, exit 0 |
| PHPCS (8.3) | 21/21 Dateien, `--report=full` ohne Eintrag = 0 Fehler, 0 Warnungen, exit 0 |
| PHPCS (7.4.33) | 21/21 Dateien, exit 0 — Regelwerk hält auch auf der Mindestversion |
| Lint (7.4.33) | 31 eigene Dateien, 31× „No syntax errors detected", 0 Abweichungen |
| PHPUnit (7.4.33) | `OK (62 tests, 540 assertions)`, exit 0, gegen Wegwerf-DB `wp_tests74`; DB danach entfernt |
| JS (CI-Job `js`) | 54 PASS / 0 FAIL und 25 bestanden / 0 Fehler, exit 0 |
| POT reproduzierbar | Neuerzeugung in Scratch-Kopie, `diff` ohne `POT-Creation-Date`: identisch, 36 msgids |
| i18n | 31 Übersetzungsaufrufe, 31 mit `sdc-page-builder` (einer mehrzeilig); PHPCS erzwingt die Domain |
| readme.txt | `check-readme.sh` → 14/14 PASS, „KONFORM", 6 Abschnitte je genau einmal |
| Slug frei | wp.org-API `sdc-page-builder` → HTTP 404 „Plugin not found"; Gegenprobe `akismet` → 200 |
| Keine IPs/Hostnamen | `git ls-files` (69 Dateien), 4 Muster: 0 Treffer. Einziger `127.0.0.1:3306` in `ci.yml` ist der GitHub-Service-Container |
| Keine Debug-Reste | `console.log`/`debugger`/`var_dump`/`print_r`: 0 Treffer. 5 `error_log` sind begründet und `phpcs:ignore`d |
| Commit, kein Push | `afa8944`, `7dae92a`; Arbeitsbaum sauber, `ahead 7` |

## Abnahme-Nachweise

| Punkt | Beleg |
|---|---|
| PHPUnit mit einem Befehl, Testzahl + „OK" | `test-scf/run-php-tests.sh` → `OK (62 tests, 540 assertions)`, exit 0 |
| Suite grün auf **beiden** Wirten | ACF Free 6.8.10 **und** SCF 6.9.7, je 62/540, exit 0 |
| PHPCS 0 Fehler | `phpcs --standard=phpcs.xml.dist` → 21 Dateien, `phpcs_exit=0`, 0 Warnungen |
| POT existiert | `plugin/languages/sdc-page-builder.pot`, 36 msgids, reproduzierbar über `test-scf/make-pot.sh` |
| `readme.txt` vorhanden + konform | `test-scf/check-readme.sh` → 14/14 PASS, „readme.txt KONFORM" |
| CI-Workflow vorhanden, lokal durchgespielt | `.github/workflows/ci.yml`, PHP 7.4 **und** 8.3 je Lint + PHPCS + PHPUnit, alle grün |
| Keine IPs/Hostnamen/Secrets | `git ls-files` (67 Dateien) gegen 5 Muster: keine Treffer |
| Commit, kein Push | `afa8944`, Arbeitsbaum sauber, `ahead 6` |

## Was die Karte verlangte und was drin ist

**PHPUnit.** 62 Tests / 540 Assertions statt der geforderten 15. Abgedeckt: Store-Roundtrip, Schema/Adapter, Renderer-Parität, Editor-Markup, Frontend-Ausgabe, REST, Uninstall.

**PHPCS.** 0 Fehler, 0 Warnungen. Die Testsuite läuft jetzt **mit** im Regelsatz — vorher deckte `tests/*` die Ebene `tests/phpunit/*` nicht ab, das Regelwerk prüfte also nur 10 von 21 Dateien. Eine Lücke, die stillschweigend als „sauber" durchgegangen wäre.

**i18n.** 30 Übersetzungsaufrufe, alle mit Textdomain `sdc-page-builder`; 0 Abweichungen. `Domain Path: /languages`.

**CI.** Bewusst beide Beine lokal ausgeführt, nicht nur eines: PHP 7.4 zusätzlich mit PHPUnit gegen eine Wegwerf-DB (`wp_tests74`), damit der geprüfte 8.3-Lauf in `wp_tests` unberührt bleibt. Die DB wurde danach entfernt.

## Gefundene und behobene Defekte

1. **`UninstallTest` zielte auf eine entfernte Funktion.** Die alte Fassung rief `sdc_pb_uninstall_sweep()`, `uninstall.php` definiert `sdc_pb_uninstall_purge()`. 4 rote Tests, und die Ersatzschleife las auf Revisionszeilen dieselben IDs endlos neu — das hat die Suite zum Hängen gebracht. Neu geschrieben (303 Zeilen, 7 Tests), jeder Purge-Test mit Zeitobergrenze, damit ein Hänger als Fehler endet statt die Suite zu blockieren.

2. **POT-Header wäre auf die falsche Support-Seite gesprungen.** Ohne `--slug` schreibt `wp i18n make-pot` `Report-Msgid-Bugs-To: .../support/plugin/plugin` — das letzte Pfadsegment wird doppelt verwendet. Die committete Datei war korrekt, eine Neuerzeugung hätte sie verschlechtert. `test-scf/make-pot.sh` hält jetzt alle drei tragenden Flags fest (`--slug`, `--exclude`, `memory_limit`); ein erneuter Lauf reproduziert die Datei byte-gleich.

3. **`make-pot` erstickte an `vendor/` und `wordpress/`.** Fatal error bei 128 MB. Ohne `--exclude` ist der Befehl nicht benutzbar — jetzt dokumentiert.

4. **Der CI-Workflow deckt SCF nicht ab — und darf das auch nicht behaupten.** `ci.yml` fährt das ACF-Bein, weil SCF nicht als frei installierbare wp.org-Version verfügbar ist. Die Parität ist damit lokal belegt (beide Beine heute frisch grün), nicht in CI. Das steht so im Kopf von `ci.yml`, damit niemand den grünen GitHub-Haken als SCF-Nachweis liest.

## Zwei Befunde aus der Nachprüfung

Beides waren Fehler in meiner eigenen Messung, nicht im Code — festgehalten, weil eine falsche Defektmeldung
teurer ist als keine:

1. **„Changelog doppelt" war ein Artefakt meines `sed`.** Die Bereichsausgabe druckte die Überschrift und die
   erste Zeile mit, das sah nach zwei Headern aus. `grep -c '^== Changelog =='` → **1**. Kein Defekt, zurückgezogen.
2. **31 Übersetzungsaufrufe, 30 mit Domain** sah nach einer Lücke aus. Der 31. ist ein mehrzeiliger Aufruf
   (`class-sdc-rest.php:246`), dessen Domain in der Folgezeile steht. 31/31. Kein Defekt.

## Umgebungs-Befund (kein Plugin-Defekt)

`wordpress.wp_postmeta` ist auf der lokalen Testinstanz **komplett leer** — 0 Zeilen über *alle* meta_keys. Die überlebenden Posts sind exakt der Purge-Belegsatz aus der Verifikation von `uninstall.php` (Seiten 82–94, angelegt 11:58–11:59). Die Prüfung lief also gegen die Dev-DB statt gegen eine Wegwerf-DB. Der M3b-Beleg auf Seite 70 hat dadurch kein Dokument-Meta mehr.

- Kein Produktionsschaden: `wordpress` ist die lokale Wegwerf-Instanz, kein Kundenstand.
- Der Purge-Code selbst ist sauber — die PHPUnit-Suite belegt ihn isoliert auf `wp_tests`.
- S7 (Deaktivieren/Aktivieren) habe ich deshalb mit einer **frisch geseedeten** Seite wiederholt: 2 Meta-Zeilen vor und nach dem Zyklus, identischer Fingerprint, Plugin wieder aktiv. Der erste Versuch hätte leer gegen leer verglichen und nichts bewiesen.

## Was bis zur Einreichung fehlt

**Nur der Nutzer kann entscheiden / liefern:**

1. **Die vier Screenshot-Dateien.** Der Abschnitt steht mit Captions, die Bilder fehlen. Dafür braucht es eine eingeloggte Admin-Sitzung auf einer geseedeten Seite; hier gibt es keine Admin-Zugangsdaten, und ich erfinde keine Bilder.
2. **wp.org-Konto und Slug-Reservierung.** Der Slug `sdc-page-builder` ist frei (API: „Plugin not found", gegengeprüft mit `akismet` und `contact-form-7`, die auflösen) — reserviert ist er damit nicht.
3. **Review durch eine zweite Person** vor dem ersten Upload.

**Fachliche Entscheidungen, die ich nicht treffen kann:**

4. **`uninstall.php` löscht nur auf ausdrückliches Opt-in** (`SDC_PB_DELETE_DATA_ON_UNINSTALL`). Bewusste Entscheidung, weil `_sdc_data` Nutzerseiten enthält — Deinstallieren darf keine Inhalte mitnehmen. Wenn wp.org ein aggressiveres Aufräumen erwartet, ist das eine Produktentscheidung.
5. **`Tested up to: 7.1`** ist gemessen (die Suite läuft gegen WP 7.1.4), nicht geschätzt. Bei einer neueren WP-Version vor dem Upload neu prüfen.
6. **`Requires at least: 6.4` ist NICHT ausgeführt, nur erklärt.** Die Suite läuft ausschließlich auf WP 7.1.4; es gibt keinen Lauf auf 6.4. Die Angabe ist eine begründete Untergrenze (REST-Namespaces, Revisions-API, Block-Editor-Markup), aber keine gemessene. Wer sie belastbar will, braucht einen Lauf gegen eine 6.4-Installation — oder eine ehrlichere Untergrenze im Header.
7. **Kein Release-Tag, keine Veröffentlichung** — laut Karte ausdrücklich nicht Teil dieses Sprints.

## Bewusst nicht gemacht

- Kein `git push`.
- Keine Screenshots erfunden und keine Platzhalterbilder erzeugt, nur um die Dateiablage zu füllen.
- Kein Release-Tag, keine Veröffentlichung.
