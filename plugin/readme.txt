=== SDC Page Builder ===
Contributors: phoenixseo
Tags: page builder, components, acf, custom fields, blocks
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://ko-fi.com/phoenixseo

Reusable page components as native blocks: pick one from the inserter, edit its fields in the sidebar, see the real markup in the canvas.

== Description ==

SDC Page Builder turns a page into a list of components. A component is a field group — heading, rich text, repeater, link, image — and every component type is its own block in the block inserter, collected under *SDC components*.

The page stores which components it uses and what values they hold, never how they look. Change a component's markup and every instance on every page changes with it — no per-page migration, and no page carrying a stale copy of the design.

A new component type is a *directory*, not a new PHP class. Drop `sdc-components/card/` with a `definition.json` in it and the editor, the inserter and the frontend output all handle it, because none of them know about specific types. The file name says nothing about the type — the group key inside it does.

= Who this is for =

Agencies and developers who build the same page patterns repeatedly (hero, FAQ, feature grid, CTA band) and want them as structured, typed content rather than free-form blocks — with one place that decides what a component looks like.

= How it works =

* Every component type is a field group with the key prefix `group_sdc_component_`. The plugin reads those groups from Advanced Custom Fields or Secure Custom Fields.
* Each type becomes a block (`sdc/<type>`) whose attributes are the field values. Because the block renders on the server, the editor canvas shows the same markup a visitor gets — not a placeholder.
* The fields are edited in the block sidebar, one form per selected component. Clicking a component in the canvas selects it; the sidebar shows its fields.
* Content lives in `post_content` as ordinary block markup, so revisions, autosaves, trash and the REST content API all work the way they do for any block.
* Component styles are collected into one inline stylesheet in the head, versioned by content hash, so a page load costs one request fewer and aggressive HTML caching stays correct.

= Features =

* **A block per component type** — each component is separately insertable, searchable by its own name, and distinguishable in the list view.
* **The category comes first** — *SDC components* sits at the top of the inserter, not somewhere below the core blocks.
* **Server-rendered** — the canvas shows the real frontend markup, so a layout bug is visible before the page is published.
* **Component types as JSON schemas** — no PHP class per type. Editor, REST layer and frontend are type-agnostic.
* **Only the fields its schema defines** — a component accepts exactly its own fields; nothing can be smuggled in as raw HTML by accident.
* **Escaped output** — stored values are escaped per field type (`esc_html`, `wp_kses_post`, `esc_attr`); the editor's data attributes never reach the frontend.
* **Permission-aware** — the REST routes check the capability to edit that specific page.
* **Backend overview** — a catalogue screen lists every component type with its fields and how many pages use it, and refuses to delete a type that is still placed.
* **i18n-ready** — all strings use the text domain `sdc-page-builder`; a POT file ships in `languages/`.
* **Your components live in your theme** — drop a folder with a JSON field group (and optionally a `render.php`) into your theme; plugin updates never touch them.

= Where do I put my own components? =

In your theme, one directory per component:

```
wp-content/themes/your-theme/sdc-components/hero/
    definition.json                  field group (required)
    render.php                       markup (optional)
    component.css                    styles (optional)
```

The plugin also reads the parent theme and `wp-content/sdc-components/`, so components survive a theme switch when they live in `wp-content`. The `sdc_pb_component_dirs` filter adds other locations.

Do not add components to the plugin's own `schemas/` directory: it ships the `hero` and `faq` examples and is replaced on every update. It follows the same layout — `schemas/hero/definition.json`.

= What are single directory components? =

A component whose definition, markup, styles and documentation live in one directory instead of being spread across `templates/`, `css/` and `js/`. The term comes from Drupal, where it has been part of core since 10.3; the idea behind it is Brad Frost's *Atomic Design* (2016, free at atomicdesign.bradfrost.com) and Pattern Lab. One folder per component means one obvious place to look, one thing to copy, and `rm -r` to remove it.

This plugin borrows the folder discipline, not the whole system. One difference matters: components are siblings in a page's list, so there are no slots for nesting one component inside another — use the core group block for layout around them.

= Can I control the HTML of a component? =

Yes, two ways. A `render.php` next to the component's JSON renders that component; it receives `$type`, `$data` (raw stored values) and `$component` (the document entry), and can call `Sdc_Frontend::component_fields_html( $type, $data )` to reuse the default field markup. The `sdc_pb_component_html` filter then has the last word over the finished HTML and can also drop a component by returning an empty string.

Without either, the plugin renders generic markup (`div.sdc-component--<type>`, one span per field) that a theme styles with CSS.

== Installation ==

1. Install and activate either [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/) or [Secure Custom Fields](https://wordpress.org/plugins/secure-custom-fields/). The plugin does nothing without one of them and says so in the admin.
2. Upload the `sdc-page-builder` folder to `/wp-content/plugins/`, or install the plugin through the WordPress *Plugins* screen.
3. Activate the plugin through the *Plugins* screen.
4. Edit a page, open the inserter and pick a component under *SDC components*.

Pages written by a pre-release version of this plugin, which kept components in a meta row instead of in the content, are converted to blocks the first time they are opened in the editor. The meta row stays as a backup; from then on the editor writes blocks.

== Frequently Asked Questions ==

= Does it work without Advanced Custom Fields? =

No. The plugin uses ACF's field API for the field definitions. Secure Custom Fields is a drop-in for that API and works the same way. Without either one, the plugin shows an admin notice and stays inert — it does not fatal.

= Where is the content stored? =

In the post content, as blocks: one `<!-- wp:sdc/<type> -->` comment per component with its field values as attributes. Nothing is kept in a separate table, which is why native revisions, autosaves and the content API include it.

= Can two components of the same type be on one page? =

Yes, as many as you like. Each block is its own instance with its own attributes.

= What about pages from the earlier builder version? =

They are converted to blocks the first time they are opened in the editor. Until then they keep rendering from their stored document, so an update in a quiet moment does not lose a page's content. The conversion is one-way by design: two stores would mean deciding which one wins.

= Does it work with caching plugins? =

Yes. Component styles are inlined once into the head with a hash-based version, so there is no separate stylesheet URL to go stale after an update, and no extra request per page. By the time a page cache sees the markup it is plain HTML.

= What happens if a component type is removed later? =

A block can outlive the plugin version that registered it. The editor marks it as unsupported and leaves its saved content alone; the rest of the page still renders. The catalogue screen refuses to delete a type while pages still use it.

== Screenshots ==

= None yet =

This release ships no screenshots. The plugin is complete and in submission
review; screenshots of the inserter, the canvas, the block sidebar and the
component catalogue will be added in the next release.

<!--
	The section stays with this note instead of being removed: a heading that
	lists no numbered item describes what the plugin offers instead of promising
	a file that is not there. The captions that were here before (inserter,
	canvas, sidebar, catalogue) are the intended shots and belong back here once
	the four PNGs exist — see assets/README.md.
-->

== Changelog ==

= 0.2.0 =
* New: the component screen carries a credit line with the author's site and a
  donation link. Sites that resell the plugin can remove it with the
  `sdc_pb_credit` filter; it appears only on this plugin's own screen.
* Fixed: converting a pre-block page to blocks could drop components. The
  conversion now adds each missing component instance instead of only flagging
  the page as converted, so a page never loses a component on its way from the
  old store to block markup.
* New: `wp sdc-pb repair [--post=<id>] [--apply]` finds pages whose stored
  document and block content disagree. It is a dry run by default; `--apply`
  writes the missing blocks. Idempotent — a second run reports nothing to do.
* New: a component whose view file is missing or no longer renders now says so.
  The failure is logged once per request and type instead of disappearing, and
  editors — never visitors — see a marker in the rendered page and a notice on
  the pages list.

= 0.1.0 =
* Initial release.
* One block per component type, registered from the component's JSON definition.
* A dedicated *SDC components* block category, first in the inserter.
* Server-side rendering, so the editor canvas shows the frontend markup.
* Component types defined as JSON schemas; editor, REST and frontend are type-agnostic.
* Field editing in the block sidebar; click a component to select it.
* One-way conversion of documents from the pre-block version into blocks, with the meta row kept as a backup.
* Host parity between Advanced Custom Fields (free) and Secure Custom Fields.
* Catalogue screen with per-type usage counts and a delete guard.
* Inline, hash-versioned component styles for cache-safe delivery.
* German source strings, POT file, and translation loading on `init`.

== Upgrade Notice ==

= 0.1.0 =
First release.
