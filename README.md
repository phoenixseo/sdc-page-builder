# SDC Page Builder

Ein komponentenbasierter Page-Builder für WordPress, der **Advanced Custom Fields** und **Secure Custom Fields** als Feld-Lieferanten nutzt — und Seiten in einem einzigen JSON-Dokument speichert.

**Status: 0.1.0-dev (Meilenstein M1 grün).** Das Fundament steht und ist gemessen; die Admin-Oberfläche fehlt noch. Das ist bewusst so herum: erst die Datenschicht beweisen, dann die Bedienung bauen.

## Warum es das gibt

ACF und SCF können Felder. Flexible Inhalte baut man in ACF Pro über »Flexible Content« — ein Formular, das Zeilen stapelt. Das funktioniert, bis die Seite wächst: eine Seite mit 30 Komponenten erzeugt **1.373 Formularfelder**, und PHP nimmt nur die ersten **1.001** an (`max_input_vars`). Der Rest wird ohne Fehlermeldung verworfen. Die Seite meldet „gespeichert" und hat ein Viertel ihres Inhalts verloren.

Zweites Problem: pro Subfeld zwei Datenbankzeilen. 100 Zeilen à 3 Felder = 602 Meta-Zeilen und **1.807 Queries** beim Speichern (733 ms). Ein einzelnes JSON-Dokument braucht **4 Queries** (8 ms) — für dieselben 30 Komponenten, die oben das Limit sprengen.

Dieser Builder löst beides, ohne die Felder neu zu erfinden: Komponenten sind ACF-Feldgruppen, definiert als JSON im Repo. Nur das Speichern und das Bauen macht er selbst.

## Was gemessen ist

| Prüfung | Ergebnis |
|---|---|
| 30 Komponenten speichern | **7,9 ms / 4 Queries** |
| Meta-Zeilen für die ganze Seite | **1** (12.583 Bytes) |
| Roundtrip (Lesen = Geschriebenes) | exakt, Umlaute intakt, IDs stabil |
| Revisionen | Dokument wird versioniert, Undo/Restore möglich |
| Unbekannter Komponententyp | wird abgewiesen, nicht still verworfen |
| Plattform | WordPress + SCF 6.9.7, PHP 8.3 |

Reproduzierbar: `wp eval-file plugin/tests/m1-verify.php 30`

## Aufbau

```
plugin/
├── sdc-page-builder.php              Bootstrap, GPL-Header, Admin-Hinweis ohne Wirt
├── includes/
│   ├── class-sdc-field-adapter.php   einzige Stelle mit acf_*-Aufrufen
│   ├── class-sdc-store.php           _sdc_data: ein Key, ein Write, Revisionen
│   ├── class-sdc-schema.php          Komponenten = Feldgruppen mit group_sdc_component_*
│   └── class-sdc-rest.php            sdc-pb/v1/pages/{id}, sdc-pb/v1/components
├── schemas/                          Komponenten als ACF-Local-JSON
└── tests/m1-verify.php               20 Prüfungen, end-to-end
```

**Datenmodell:** ein Meta-Key pro Seite.

```json
{
  "v": 1,
  "components": [
    { "_id": "c_7f3a91bd", "type": "faq", "schema": "faq@1", "data": { "items": [ ... ] } }
  ]
}
```

`_id` gibt jeder Komponente eine stabile Identität für Sortieren und Undo. `schema` erlaubt Versionierung: alte Daten bleiben lesbar, migriert wird beim Lesen.

**Komponenten definieren:** eine Feldgruppe als JSON in `plugin/schemas/`, Key mit Präfix `group_sdc_component_`. Der Rest des Keys ist der Typ (`group_sdc_component_faq` → `faq`). Eine Location-Regel auf `sdc_component == 1` sorgt dafür, dass der Wirt die Gruppe nie als normale Metabox rendert.

## Voraussetzungen

- WordPress 6.4+
- PHP 7.4+
- **Advanced Custom Fields** (free) **oder** Secure Custom Fields — je eines, nicht beide

Ohne Wirt aktiviert sich das Plugin nicht und zeigt einen Hinweis statt eines Fatal Errors.

## Installation

```bash
git clone https://github.com/phoenixseo/sdc-page-builder.git
ln -s "$PWD/sdc-page-builder/plugin" wp-content/plugins/sdc-page-builder
wp plugin activate sdc-page-builder
```

## Was noch fehlt

M1 ist die Datenschicht. Es folgen: Admin-Oberfläche (Komponentenliste, Umsortieren, Vorschau), Renderer fürs Frontend, Undo/Restore-UI, Import/Export.

## Lizenz

GPL-2.0-or-later. Siehe [LICENSE](LICENSE).

Dieses Projekt ist unabhängig. Es steht in keiner Verbindung zu WP Engine oder Automattic und wird von beiden nicht unterstützt oder gesponsert. „Advanced Custom Fields" und „ACF" sind Marken von WP Engine; „Secure Custom Fields" ist ein Projekt von WordPress.org. Die Namen werden hier nur beschreibend verwendet, um die Kompatibilität zu benennen.
