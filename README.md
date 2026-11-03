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
├── schemas/                          mitgelieferte Beispiel-Komponenten (hero, faq)
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

**Komponenten definieren:** eine Feldgruppe als JSON mit Key-Präfix `group_sdc_component_`. Der Rest des Keys ist der Typ (`group_sdc_component_faq` → `faq`). Eine Location-Regel auf `sdc_component == 1` sorgt dafür, dass der Wirt die Gruppe nie als normale Metabox rendert.

## Eigene Komponenten anlegen

Ein Komponententyp ist ein Verzeichnis — JSON für die Felder, optional `render.php` für das Markup:

```
wp-content/themes/<dein-theme>/sdc-components/
└── hero/
    ├── group_sdc_component_hero.json    Feldgruppe (Pflicht)
    └── render.php                       Markup (optional)
```

Gesucht wird in dieser Reihenfolge, der erste Treffer gewinnt:

1. `get_stylesheet_directory()/sdc-components/` — Child-Theme
2. `get_template_directory()/sdc-components/` — Parent-Theme
3. `WP_CONTENT_DIR/sdc-components/` — überlebt einen Theme-Wechsel

Andere Orte per Filter:

```php
add_filter( 'sdc_pb_component_dirs', function ( $dirs ) {
    $dirs[] = __DIR__ . '/meine-komponenten';
    return $dirs;
} );
```

**Nicht** in `plugin/schemas/` schreiben: dort liegen nur die mitgelieferten Beispiele (`hero`, `faq`), und das Verzeichnis gehört dem Plugin — eigene Dateien sind beim nächsten Update weg.

### `render.php`

Die Datei läuft im Scope der Renderer-Methode und sieht drei Variablen:

| Variable | Inhalt |
|---|---|
| `$type` | Komponententyp, z. B. `hero` |
| `$data` | gespeicherte Werte dieses Exemplars, roh aus dem Dokument |
| `$component` | kompletter Dokumenteintrag (`_id`, `type`, `schema`, `data`) |

```php
<article class="hero">
	<h2><?php echo esc_html( $data['heading'] ); ?></h2>
	<p><?php echo esc_html( $data['subline'] ); ?></p>
	<?php echo Sdc_Frontend::component_fields_html( $type, $data ); ?>
</article>
```

Zwei Dinge sind bewusst so: `$data` kommt **unescaped** an (Escaping ist Aufgabe des Templates — es ist Theme-Code, gleiche Vertrauensgrenze wie ein Template-Part), und `Sdc_Frontend::component_fields_html()` liefert das Standard-Markup der Felder, wenn nur der Rahmen eigen sein soll. Ohne `render.php` rendert das Plugin generisch (`div.sdc-component--<typ>` mit `sdc-field--<name>`-Spans).

### Letzter Eingriff: der Filter

```php
add_filter( 'sdc_pb_component_html', function ( $html, $type, $data, $component ) {
    if ( 'hero' === $type && empty( $data['heading'] ) ) {
        return ''; // Komponente weglassen
    }
    return $html;
}, 10, 4 );
```

Der Filter läuft **nach** dem Template und hat das letzte Wort; ein leerer Rückgabewert entfernt die Komponente von der Seite.

### Ein Verzeichnis pro Komponente — warum

ACF liest Local JSON nur eine Ebene tief (`scan_files()` überspringt Unterverzeichnisse). Das Plugin meldet deshalb jedes Unterverzeichnis von `sdc-components/` einzeln als Ladepfad an — dadurch funktioniert „ein Ordner pro Komponente" mit unverändertem ACF-Loader. Eine flache Ablage (`sdc-components/hero.json`) geht ebenfalls.

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

Das Plugin ist funktional vollständig: Editor, REST-Speicherung, Revisionsverlauf, Import/Export, Frontend-Ausgabe und Theme-Templates. Offen ist die Freigabe für WordPress.org (Screenshots, Zweit-Review) sowie Live-Vorschau und Undo im Editor.

## Lizenz

GPL-2.0-or-later. Siehe [LICENSE](LICENSE).

Dieses Projekt ist unabhängig. Es steht in keiner Verbindung zu WP Engine oder Automattic und wird von beiden nicht unterstützt oder gesponsert. „Advanced Custom Fields" und „ACF" sind Marken von WP Engine; „Secure Custom Fields" ist ein Projekt von WordPress.org. Die Namen werden hier nur beschreibend verwendet, um die Kompatibilität zu benennen.
