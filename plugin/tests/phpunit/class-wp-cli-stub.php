<?php
/**
 * Recording stand-in for WP-CLI, for the suite only.
 *
 * Two jobs:
 *
 * 1. `WP_CLI` must be defined *before* the plugin boots, because the repair
 *    command is defined only under it. Loading the plugin file later is not
 *    enough: `require_once` has already recorded the path, so the second load
 *    returns without executing anything.
 * 2. The command reports through a static API. Instead of printing, the calls
 *    are recorded, so the tests can assert on the report as data.
 *
 * `error()` throws: the command is expected to end there, and a test that went
 * on would read a state a real run never reaches.
 *
 * @package SdcPageBuilder
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	/*
	 * The plugin's gate reads `defined( 'WP_CLI' ) && WP_CLI` — a global
	 * constant, not the class. A class constant of the same name does not
	 * create it, so it has to be defined explicitly; the constant is what the
	 * plugin sees, the class is what the command calls.
	 */
	if ( ! defined( 'WP_CLI' ) ) {
		define( 'WP_CLI', true );
	}

	/**
	 * Recording stand-in for WP-CLI.
	 */
	class WP_CLI {

		/**
		 * Marker for code that asks the class rather than the constant.
		 */
		const WP_CLI = true;

		/**
		 * Commands registered through add_command().
		 *
		 * @var array<string,mixed>
		 */
		public static $commands = array();

		/**
		 * Recorded output, one entry per call.
		 *
		 * @var array[] Each: `type` (log|success|warning|error) and `text`.
		 */
		public static $calls = array();

		/**
		 * Reset the recording.
		 */
		public static function capture() {
			self::$calls = array();
		}

		/**
		 * Plain line.
		 *
		 * @param string $message Message.
		 */
		public static function log( $message ) {
			self::$calls[] = array(
				'type' => 'log',
				'text' => (string) $message,
			);
		}

		/**
		 * Success line.
		 *
		 * @param string $message Message.
		 */
		public static function success( $message ) {
			self::$calls[] = array(
				'type' => 'success',
				'text' => (string) $message,
			);
		}

		/**
		 * Warning line.
		 *
		 * @param string $message Message.
		 */
		public static function warning( $message ) {
			self::$calls[] = array(
				'type' => 'warning',
				'text' => (string) $message,
			);
		}

		/**
		 * Fatal line: recorded, then thrown.
		 *
		 * @param string $message Message.
		 * @throws RuntimeException Always.
		 */
		public static function error( $message ) {
			$message       = (string) $message;
			self::$calls[] = array(
				'type' => 'error',
				'text' => $message,
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, never printed as HTML.
			throw new RuntimeException( $message );
		}

		/**
		 * Register a command.
		 *
		 * @param string $name       Command name.
		 * @param mixed  $command    Callable or class name.
		 */
		public static function add_command( $name, $command ) {
			self::$commands[ (string) $name ] = $command;
		}

		/**
		 * Recorded lines of one type, in call order.
		 *
		 * @param string $type log|success|warning|error.
		 * @return string[]
		 */
		public static function lines( $type = 'log' ) {
			$texts = array();

			foreach ( self::$calls as $call ) {
				if ( $type === $call['type'] ) {
					$texts[] = $call['text'];
				}
			}

			return $texts;
		}
	}
}
