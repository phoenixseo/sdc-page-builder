# SDC Page Builder

Ein komponentenbasierter Page-Builder für WordPress, der **Advanced Custom Fields** und **Secure Custom Fields** als Feld-Lieferanten nutzt — und Seiten in einem einzigen JSON-Dokument speichert.

**Status: 0.1.0.** Editor, Speicherung, Frontend-Ausgabe, Revisionen und Import/Export stehen und sind gemessen. Offen ist die Freigabe für WordPress.org sowie Live-Vorschau und Undo im Editor.

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

## Was Single Directory Components sind

Eine Komponente will man anfassen können, ohne sie zu suchen. **Single Directory Components** (SDC, „Komponente in einem Verzeichnis") heißt genau das: Definition, Markup, Styles, Skripte und Doku eines Bausteins liegen in **einem Ordner**. Nicht verstreut über `templates/`, `css/` und `js/`.

Der Name stammt aus Drupal. Dort war SDC ab Version 10.1 ein experimentelles Modul und gehört seit 10.3 zum Core. Die Konvention sieht so aus:

```
my_theme/components/card/
├── card.component.yml    Metadaten: Props und Slots
├── card.twig             Markup
├── card.css              Styles, beim Rendern automatisch geladen
└── card.js               Verhalten, ebenfalls automatisch geladen
```

Drupal prüft jedes Blattverzeichnis unter `components/` auf eine `.component.yml`. Fehlt sie, wird der Ordner ignoriert. Deshalb darf man `atoms/`, `molecules/` und `organisms/` dazwischenlegen, ohne die Erkennung zu stören — gezählt wird das Blatt. Die `.component.yml` beschreibt die **Props** (Werte, die die Komponente erwartet) und die **Slots** (Inhalte, die man hineinsteckt).

Der Gewinn ist nicht das Dateiformat, sondern die Frage „wo gehört das hin?". Sie hat genau eine Antwort. Und Wegwerfen ist ein `rm -r`.

### Woher die Idee kommt

Brad Frost hat das Muster in *Atomic Design* beschrieben (2016, kostenlos online unter [atomicdesign.bradfrost.com](https://atomicdesign.bradfrost.com/)): Oberflächen bestehen aus Bausteinen, die sich zu größeren Bausteinen zusammensetzen — Atome, Moleküle, Organismen, Templates, Seiten. Sein Werkzeug dafür war [Pattern Lab](https://patternlab.io/), ein statischer Generator, der Muster aus Ordnern einliest, ineinander verschachtelt und als Pattern Library ausgibt.

Zwei Gedanken daraus tragen bis heute:

- **Verschachteln statt kopieren.** Wer die Karte ändert, ändert alle Karten. Frost vergleicht das mit russischen Puppen.
- **Herkunft mitdenken.** Pattern Lab zeigt zu jedem Muster, woraus es besteht und wo es verwendet wird. Das ist die Grundlage für die Frage „was bricht, wenn ich das ändere?".

Der Begriff SDC ist Drupals Name für diese Disziplin, nicht Frosts. Die Idee dahinter ist seine.

### Was man damit anstellen kann

- **Ein Baustein, viele Seiten.** Der Typ wird einmal definiert. Jede Seite speichert nur ihre Werte und eine `_id` — nicht eine Kopie des Markups.
- **Mehrere Exemplare pro Seite.** Zwei FAQ-Blöcke sind zwei Einträge desselben Typs, jeder mit eigenem Präfix. Sie kollidieren nicht.
- **Markup gehört dem Theme.** Ein `render.php` ändert das Aussehen einer Komponente, ohne das Plugin anzufassen — und ohne dass das nächste Plugin-Update die Änderung überschreibt.
- **Weiterentwickeln ohne Altlasten.** Das Dokument trägt `schema: "teaser@1"`. Kommen Felder dazu, bleibt der alte Inhalt lesbar.
- **Umziehen.** Ein Ordner kopieren bringt die Komponente in ein anderes Projekt. Inhalte gehen als JSON-Export mit.
- **Bedingt eingreifen.** Ein Filter kann eine Komponente umschreiben oder weglassen — etwa für Besucher ohne Anmeldung.
- **Design-System ohne Zusatzwerkzeug.** Eine Seite, die jede Komponente einmal zeigt, ist die Pattern Library. Dafür braucht es kein Storybook und keinen zweiten Build.
- **Außerhalb des Builders weiterverwenden.** `Sdc_Frontend::component_fields_html( $type, $data )` liefert dasselbe Feldmarkup in einem normalen Theme-Template. Die Felddefinition gilt also an beiden Stellen.

### Und im SDC Page Builder

| Drupal SDC | SDC Page Builder |
|---|---|
| `components/<name>/` | `sdc-components/<name>/` |
| `<name>.component.yml` — Props und Slots | `group_sdc_component_<name>.json` — ACF-Feldgruppe, Felder sind die Props |
| `<name>.twig` | `render.php` |
| `<name>.css`, `<name>.js`, automatisch geladen | nicht automatisch — das Theme lädt seine Assets wie gewohnt |
| Props kommen beim Rendern als Argumente | `$data` im Template, die gespeicherten Werte |
| Slots für verschachtelte Inhalte | keine — Komponenten sind Geschwister in einer Liste |

Zwei Unterschiede sollte man kennen, bevor man SDC-Erwartungen überträgt:

**Keine Slots.** Eine Komponente nimmt keine anderen Komponenten als Inhalt auf. Verschachtelung entsteht über die Reihenfolge auf der Seite, nicht über Parameter. Wer tiefer verschachteln will, baut den Container als eigenen Typ mit eigenen Feldern.

**Keine automatischen Assets.** `render.php` liefert Markup, sonst nichts. CSS und JS kommen aus der normalen Asset-Kette des Themes. Das ist Absicht: ein Plugin, das ungefragt Stylesheets einbindet, kollidiert mit jedem Build-Setup.

### Beispiel: eine Teaser-Komponente

Zwei Dateien in einem Ordner, mehr braucht es nicht.

```
wp-content/themes/<dein-theme>/sdc-components/teaser/
├── group_sdc_component_teaser.json
└── render.php
```

**1. Die Felder** — `group_sdc_component_teaser.json`:

```json
{
  "key": "group_sdc_component_teaser",
  "title": "Teaser",
  "sdc_description": "Karte mit Überschrift, Text und Link.",
  "sdc_icon": "screenoptions",
  "sdc_version": 1,
  "fields": [
    { "key": "field_sdc_teaser_heading", "label": "Überschrift", "name": "heading", "type": "text", "required": 1 },
    { "key": "field_sdc_teaser_text", "label": "Text", "name": "text", "type": "textarea", "required": 0, "rows": 3 },
    { "key": "field_sdc_teaser_link", "label": "Link", "name": "link", "type": "link", "required": 0, "return_format": "array" }
  ],
  "location": [
    [ { "param": "sdc_component", "operator": "==", "value": "1" } ]
  ],
  "menu_order": 0,
  "position": "normal",
  "style": "default",
  "label_placement": "top",
  "instruction_placement": "label",
  "active": true,
  "description": ""
}
```

Drei Dinge sind daran wichtig: Der Key beginnt mit `group_sdc_component_`, der Rest ist der Typ (`teaser`). Jedes Feld hat einen eigenen Key und einen `name`, unter dem der Wert im Dokument landet. Und die Location-Regel `sdc_component == 1` hält die Gruppe von den normalen Metaboxen fern.

**2. Das Markup** — `render.php`:

```php
<?php
$link = isset( $data['link'] ) && is_array( $data['link'] ) ? $data['link'] : array();
?>
<article class="teaser">
	<h3 class="teaser__title"><?php echo esc_html( $data['heading'] ?? '' ); ?></h3>

	<?php if ( ! empty( $data['text'] ) ) : ?>
		<p class="teaser__text"><?php echo esc_html( $data['text'] ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $link['url'] ) ) : ?>
		<a class="teaser__link" href="<?php echo esc_url( $link['url'] ); ?>">
			<?php echo esc_html( $link['title'] ?? $link['url'] ); ?>
		</a>
	<?php endif; ?>
</article>
```

**3. Benutzen.** Seite öffnen, im Builder „Teaser" wählen, Felder füllen, speichern. Im Frontend steht `<article class="teaser">`; von der Komponente selbst ist im HTML nichts zu sehen, was an den Builder erinnert.

**4. Stylen.** `render.php` liefert nur Markup. Das CSS gehört ins Theme — `wp_enqueue_style()` in `functions.php`, ein Stylesheet pro Komponente, oder was dein Build ohnehin macht.

Wer die Felder nachträglich ändert, ändert den Typ für **alle** Seiten, die ihn benutzen. Neue Felder sind unkritisch. Ein Feld umbenennen heißt: alte Werte liegen unter dem alten Namen. Deshalb ist `sdc_version` im JSON und `schema` im Dokument — die Migration hängt an der Version, nicht am Zufall.

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
