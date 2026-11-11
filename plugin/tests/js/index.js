/**
 * Directory entry point for the JS suite.
 *
 * `node --test plugin/tests/js/` is the documented command, and Node resolves
 * a directory argument through its `index.js` before it looks for test files.
 * This file only forwards to the suite, so the command works whether Node
 * treats the argument as a directory of tests or as a module entry.
 *
 * The suites themselves live beside this file; nothing is defined here. Both
 * are required so `node --test plugin/tests/js/` runs them in one process:
 * Node resolves the directory through this file and would otherwise run only
 * the suite named below.
 */

'use strict';

require( './inspector-rows.test.js' );
require( './media-field.test.js' );
