# K7 — Release-Reife: Bericht

Stand: 2026-11-03 · Commit `afa8944` · kein Push (`main` ist 6 Commits vor `origin/main`)

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
6. **Kein Release-Tag, keine Veröffentlichung** — laut Karte ausdrücklich nicht Teil dieses Sprints.

## Bewusst nicht gemacht

- Kein `git push`.
- Keine Screenshots erfunden und keine Platzhalterbilder erzeugt, nur um die Dateiablage zu füllen.
- Kein Release-Tag, keine Veröffentlichung.
