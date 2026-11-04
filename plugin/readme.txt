=== SDC Page Builder ===
Contributors: phoenixseo
Tags: page builder, components, acf, custom fields, structured data
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build pages from reusable components that are defined as field groups and stored as a single JSON document.

== Description ==

SDC Page Builder turns a page into a list of components. A component is a field group — heading, rich text, repeater, link, image — and the whole page is stored in one JSON document with a format version and a revision history.

This separates content from markup, which most page builders do not: a page stores which components it uses and what values they hold, never how they look. Change a component's markup and every instance on every page changes with it — no per-page migration, and no page carrying a stale copy of the design.

A new component type is a *directory*, not a new PHP class. Drop `sdc-components/card/` with a `definition.json` in it and the editor, the save route and the frontend output all handle it, because none of them know about specific types. The file name says nothing about the type — the group key inside it does.

= Who this is for =

Agencies and developers who build the same page patterns repeatedly (hero, FAQ, feature grid, CTA band) and want them as structured content rather than free-form blocks — with the content stored in a shape that can be exported, imported, versioned and rendered by code.

= How it works =

* Every component type is a field group with the key prefix `group_sdc_component_`. The plugin reads those groups from Advanced Custom Fields or Secure Custom Fields.
* The editor renders the fields through the host field plugin, one instance per component, with instance-prefixed input names so two components of one type never overwrite each other.
* Saving writes one meta row (`_sdc_data`) for the whole page and creates a revision.
* The frontend appends the rendered components to the post content, escaped, with no editor attributes.

= Features =

* **Component types as JSON schemas** — no PHP class per type. The editor, REST layer and frontend are type-agnostic.
* **One JSON document per page** — one meta row, a format version, and schema-version strings per component (`faq@1`).
* **Revisions and history** — restore any stored revision; the current state is snapshotted before it is overwritten.
* **Import and export** — a JSON file per page, validated on import (unknown type, unknown field, missing required field, broken JSON); a rejected import leaves the document untouched.
* **Host parity** — runs on Advanced Custom Fields (free) and on Secure Custom Fields, with the same behaviour on both.
* **Escaped output** — stored values are never treated as HTML; the editor's data attributes do not reach the frontend.
* **Permission-aware REST routes** — every route checks the capability to edit that specific page.
* **i18n-ready** — all strings use the text domain `sdc-page-builder`; a POT file ships in `languages/`.
* **Your components live in your theme** — drop a folder with a JSON field group (and optionally a `render.php`) into your theme; plugin updates never touch them.

= Where do I put my own components? =

In your theme, one directory per component:

```
wp-content/themes/your-theme/sdc-components/hero/
    definition.json                  field group (required)
    render.php                       markup (optional)
```

The plugin also reads the parent theme and `wp-content/sdc-components/`, so components survive a theme switch when they live in `wp-content`. The `sdc_pb_component_dirs` filter adds other locations.

Do not add components to the plugin's own `schemas/` directory: it ships the `hero` and `faq` examples and is replaced on every update. It follows the same layout — `schemas/hero/definition.json`.

= What are single directory components? =

A component whose definition, markup, styles and documentation live in one directory instead of being spread across `templates/`, `css/` and `js/`. The term comes from Drupal, where it has been part of core since 10.3; the idea behind it is Brad Frost's *Atomic Design* (2016, free at atomicdesign.bradfrost.com) and Pattern Lab. One folder per component means one obvious place to look, one thing to copy, and `rm -r` to remove it.

This plugin borrows the folder discipline, not the whole system. Two differences matter: components are siblings in a page's list, so there are no slots for nesting other components inside one; and the plugin attaches no CSS or JS, so styles stay in your theme's asset pipeline. See the repository README for the full mapping.

= Can I control the HTML of a component? =

Yes, two ways. A `render.php` next to the component's JSON renders that component; it receives `$type`, `$data` (raw stored values) and `$component` (the document entry), and can call `Sdc_Frontend::component_fields_html( $type, $data )` to reuse the default field markup. The `sdc_pb_component_html` filter then has the last word over the finished HTML and can also drop a component by returning an empty string.

Without either, the plugin renders generic markup (`div.sdc-component--<type>`, one span per field) that a theme styles with CSS.

== Installation ==

1. Install and activate either [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/) or [Secure Custom Fields](https://wordpress.org/plugins/secure-custom-fields/). The plugin does nothing without one of them and says so in the admin.
2. Upload the `sdc-page-builder` folder to `/wp-content/plugins/`, or install the plugin through the WordPress *Plugins* screen.
3. Activate the plugin through the *Plugins* screen.
4. Edit a page. The builder appears below the editor.

== Frequently Asked Questions ==

= Does it work without Advanced Custom Fields? =

No. The plugin uses ACF's field API to render inputs. Secure Custom Fields is a drop-in for that API and works the same way. Without either one, the plugin shows an admin notice and stays inert — it does not fatal.

= Where is the content stored? =

In one post meta row per page, `_sdc_data`, holding `{"v":1,"components":[...]}`. Nothing is written to post content beyond whatever the page already had.

= Can two components of the same type be on one page? =

Yes. Each instance gets its own id and its own input-name prefix, so they do not collide.

= What happens if a component type is removed later? =

A stored document can outlive the plugin version that wrote it. An unknown type is skipped and logged on output; the rest of the page still renders. Importing such a document is refused, so nothing is silently dropped on write.

= Does it work with the block editor? =

The builder is its own panel below the editor; it does not add blocks. Both editors can be active, but content written in blocks is not managed by the builder.

= Is the JSON document portable? =

Yes. *Export* produces a JSON file for the page, *Import* accepts it on another page or site of the same component version. Unknown fields and missing required fields are rejected with a reason, and the target document is left unchanged.

== Screenshots ==

1. The builder panel on a page: component list, add-component control, and the save status line.
2. Editing a component instance, rendered by the host field plugin.
3. The history panel with stored revisions and the restore control.
4. The same document rendered in the frontend.

<!--
	The four screenshot files (screenshot-1.png … screenshot-4.png) are not part
	of this repository yet. They need an authenticated admin session on a seeded
	site to capture, which this environment does not have. Add them to assets/
	before submitting to wp.org; until then the list above stays as the intended
	captions. Tracked in the release report.
-->

== Changelog ==

= 0.1.0 =
* Initial release.
* Component types defined as JSON schemas; editor, REST and frontend are type-agnostic.
* Single JSON document per page with format version and revision history.
* Import and export with validation that refuses partial writes.
* Host parity between Advanced Custom Fields (free) and Secure Custom Fields.
* REST routes for reading, saving, history, restore, import and export, each permission-checked per page.
* German source strings, POT file, and translation loading on `init`.

== Upgrade Notice ==

= 0.1.0 =
First release.
