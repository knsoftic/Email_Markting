<?php

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Serialise concurrent test runs
|--------------------------------------------------------------------------
| The suite runs against a real MySQL schema (see SETUP.md for why), and
| RefreshDatabase drops every table at the start of a run. Two overlapping
| `php artisan test` invocations therefore destroy each other's tables
| mid-flight, producing a wall of "Table 'migrations' doesn't exist" errors
| that look like a code failure and are not.
|
| An exclusive file lock makes the second run wait instead. The lock is
| released automatically when the process exits, so a crashed run cannot
| leave it stuck.
|
| Set KNS_TEST_NO_LOCK=1 to opt out (for example when using --parallel, which
| gives each token its own database already).
*/

if (! getenv('KNS_TEST_NO_LOCK')) {
    $lockFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'knsoftic-test.lock';
    $handle = @fopen($lockFile, 'c');

    if ($handle !== false) {
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fwrite(STDERR, "Another test run is using the test database — waiting for it to finish...\n");
            flock($handle, LOCK_EX);
        }

        // Held for the lifetime of this process; released on exit.
        $GLOBALS['__knsoftic_test_lock'] = $handle;
    }
}
