<?php
/**
 * Repair command for pages that are already marked migrated.
 *
 * A run that set the marker while a component was still missing left the page
 * permanently short of that component: the automatic migration skips marked
 * pages, so nothing would look at them again. This command looks, and on
 * request writes what is missing.
 *
 * It deliberately has no user interface. A button a content editor can press
 * is a write to other people's pages, and nobody has decided yet that the
 * plugin may do that on its own — that decision belongs to the site owner.
 * WP-CLI is the one entry point: whoever can run it already has shell access
 * to the database, so the command grants no rights that did not exist before.
 * On the web there is no admin page, no action and no route that reaches it.
 *
 * The dry run and the write share one code path. `--apply` calls
 * `migrate_post()` with `$force`, the function the automatic run calls too;
 * without the flag the same comparison is read through `missing_types()` and
 * nothing is written — not the content, not the marker, not a revision. Two
 * comparisons would be free to disagree, and the dry run would then lie about
 * what `--apply` does. Nothing is ever deleted or reordered: only components
 * without a block are appended.
 *
 * Usage:
 *
 *     wp sdc-pb repair                     # dry run
 *     wp sdc-pb repair --post=98           # one page
 *     wp sdc-pb repair --apply --post=98   # write
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Report and repair pages whose content lacks a component of its document.
	 */
	class Sdc_Repair {

		/**
		 * Report pages one component short, without writing.
		 *
		 * Prints `#<id> <titel> fehlt=[hero,faq]` per affected page. Without
		 * the flag nothing is written: not the content, not the marker, not a
		 * revision, and not the meta of a page that is not marked either.
		 *
		 * ## OPTIONS
		 *
		 * [--apply]
		 * : Write the missing components. Without it the command is a dry run.
		 *
		 * [--post=<id>]
		 * : Look at one page only.
		 *
		 * ## EXAMPLES
		 *
		 *     wp sdc-pb repair
		 *     wp sdc-pb repair --post=98
		 *     wp sdc-pb repair --apply --post=98
		 *
		 * @param string[]            $args       Positional arguments; unused.
		 * @param array<string,mixed> $assoc_args Flags.
		 */
		public function repair( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
			$apply = ! empty( $assoc_args['apply'] );
			$pages = $this->pages( $assoc_args );

			if ( null === $pages ) {
				WP_CLI::error( 'Seite nicht gefunden oder kein unterstützter Inhaltstyp.' );
			}

			$found   = 0;
			$written = 0;

			foreach ( $pages as $post ) {
				$missing = Sdc_Migration::missing_types( $post->ID );

				if ( empty( $missing ) ) {
					continue;
				}

				++$found;
				WP_CLI::log( $this->line( $post, $missing ) );

				if ( ! $apply ) {
					continue;
				}

				if ( Sdc_Migration::migrate_post( $post->ID, true ) ) {
					++$written;
					continue;
				}

				// The run found components missing and wrote none: the inner
				// update failed, or the document changed under it. Either way
				// the page is as it was, and staying quiet would read as
				// success.
				WP_CLI::warning( sprintf( 'Seite #%d konnte nicht ergänzt werden.', $post->ID ) );
			}

			if ( 0 === $found ) {
				WP_CLI::success( 'Keine betroffene Seite gefunden.' );
				return;
			}

			if ( ! $apply ) {
				WP_CLI::success( sprintf( '%d betroffene Seite(n), Trockenlauf — nichts geschrieben.', $found ) );
				return;
			}

			WP_CLI::success( sprintf( '%d betroffene Seite(n), %d ergänzt.', $found, $written ) );
		}

		/**
		 * The posts to look at.
		 *
		 * @param array<string,mixed> $assoc_args Flags.
		 * @return WP_Post[]|null Posts, or null when `--post` names a page that
		 *                        cannot be repaired.
		 */
		private function pages( array $assoc_args ) {
			if ( isset( $assoc_args['post'] ) ) {
				$post = get_post( (int) $assoc_args['post'] );

				if ( ! $post || ! in_array( $post->post_type, Sdc_Editor::post_types(), true ) ) {
					return null;
				}

				return array( $post );
			}

			return get_posts(
				array(
					'post_type'        => Sdc_Editor::post_types(),
					'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'numberposts'      => -1,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			);
		}

		/**
		 * One report line.
		 *
		 * @param WP_Post  $post    Post.
		 * @param string[] $missing Missing component types.
		 * @return string
		 */
		private function line( $post, array $missing ) {
			$title = trim( (string) $post->post_title );

			return sprintf(
				'#%d %s fehlt=[%s]',
				$post->ID,
				'' === $title ? '(ohne Titel)' : $title,
				implode( ',', $missing )
			);
		}
	}

	/*
	 * Register the namespace, not the subcommand.
	 *
	 * The class's method is `repair()`, so `sdc-pb` plus that method makes
	 * `wp sdc-pb repair`. Registering `sdc-pb repair` here would produce
	 * `wp sdc-pb repair repair` — the command would list itself in the help
	 * and never run the subcommand anyone types.
	 */
	WP_CLI::add_command( 'sdc-pb', 'Sdc_Repair' );
}
