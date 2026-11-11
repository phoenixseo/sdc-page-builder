<?php
/**
 * Tabs for the component screen.
 *
 * The component screen edits three different things: the field list, the PHP
 * view, and the stylesheet. Stacked as three long textareas they read as one
 * wall of code and the field list — the part that is edited most — ends up
 * above a screen of PHP. Tabs separate them without hiding anything: all
 * three are always in the DOM, so a save carries whatever the author typed in
 * the ones they did not look at, and a browser without JavaScript shows all
 * three one below the other.
 *
 * Markup only: no state, no storage, no knowledge of what a tab contains.
 *
 * @package SdcPageBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints a tab list and its panels.
 */
class Sdc_Tabs {

	/**
	 * Render a set of tabs.
	 *
	 * Every tab is an array with:
	 *
	 * - `id`       (string)   Slug; becomes the element ids, so it must be a
	 *                         valid HTML id — `sanitize_key()` is applied.
	 * - `label`    (string)   What the button says.
	 * - `hint`     (string)   Optional. One sentence below the heading, for
	 *                         the reader who wonders what the tab is for.
	 * - `callback` (callable) Prints the panel body.
	 *
	 * Panels are rendered open and unhidden; `admin-tabs.js` collapses them.
	 * A tab that cannot be rendered (no id, no callable) is skipped rather
	 * than printed as an empty panel.
	 *
	 * @param array[] $tabs   Tab definitions.
	 * @param string  $active Id of the tab to show first; falls back to the first one.
	 * @param string  $label  Accessible name of the tab list.
	 */
	public static function render( array $tabs, $active = '', $label = '' ) {
		$ready = array();

		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) || ! isset( $tab['id'], $tab['callback'] ) || ! is_callable( $tab['callback'] ) ) {
				continue;
			}

			$id = sanitize_key( (string) $tab['id'] );

			if ( '' === $id ) {
				continue;
			}

			$tab['id']    = $id;
			$tab['label'] = isset( $tab['label'] ) ? (string) $tab['label'] : $id;
			$tab['hint']  = isset( $tab['hint'] ) ? (string) $tab['hint'] : '';
			$ready[]      = $tab;
		}

		if ( empty( $ready ) ) {
			return;
		}

		$active  = sanitize_key( (string) $active );
		$current = $ready[0]['id'];

		foreach ( $ready as $tab ) {
			if ( $tab['id'] === $active ) {
				$current = $tab['id'];
			}
		}

		echo '<div class="sdc-tabs">';

		printf(
			'<div class="sdc-tabs__nav" role="tablist"%s>',
			'' === (string) $label ? '' : ' aria-label="' . esc_attr( (string) $label ) . '"'
		);

		foreach ( $ready as $tab ) {
			$is_current = $tab['id'] === $current;

			printf(
				'<button type="button" role="tab" class="sdc-tabs__tab" id="sdc-tab-%1$s" aria-controls="sdc-panel-%1$s" aria-selected="%2$s" tabindex="%3$s" data-sdc-tab="%1$s">%4$s</button>',
				esc_attr( $tab['id'] ),
				$is_current ? 'true' : 'false',
				$is_current ? '0' : '-1',
				esc_html( $tab['label'] )
			);
		}

		echo '</div>';

		foreach ( $ready as $tab ) {
			/*
			 * No `aria-hidden` here: the panels are visible until the script
			 * collapses them, and a panel that is on screen but hidden from
			 * assistive technology is worse than no tabs at all. The script
			 * sets the attribute when it hides a panel, so the two agree.
			 */
			printf(
				'<section class="sdc-tabs__panel" role="tabpanel" id="sdc-panel-%1$s" aria-labelledby="sdc-tab-%1$s" data-sdc-panel="%1$s">',
				esc_attr( $tab['id'] )
			);

			printf( '<h3 class="sdc-tabs__title">%s</h3>', esc_html( $tab['label'] ) );

			if ( '' !== $tab['hint'] ) {
				printf( '<p class="sdc-tabs__hint">%s</p>', esc_html( $tab['hint'] ) );
			}

			call_user_func( $tab['callback'] );

			echo '</section>';
		}

		echo '</div>';
	}
}
