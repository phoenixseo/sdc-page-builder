/**
 * Directory entry point for the JS suite.
 *
 * `node --test plugin/tests/js/` is the documented command, and Node resolves
 * a directory argument through its `index.js` before it looks for test files.
 * This file only forwards to the suite, so the command works whether Node
 * treats the argument as a directory of tests or as a module entry.
 *
 * The suite itself lives in `inspector-rows.test.js`; nothing is defined here.
 */

'use strict';

require( './inspector-rows.test.js' );
