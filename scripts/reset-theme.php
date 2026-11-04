<?php

/**
 * Switches all accounts and the panel default that use the Agency theme back
 * to Froxlor's own theme (the dark variant to Froxlor's dark variant).
 *
 *   php scripts/reset-theme.php /var/www/froxlor
 *   php scripts/reset-theme.php /var/www/froxlor --dry-run
 *
 * Called by uninstall.sh before the theme directory is removed. Connects with
 * the credentials from Froxlor's lib/userdata.inc.php.
 */

declare(strict_types=1);

// Command line only. Should this directory ever end up below a web root, it
// must not be executable from a browser.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/lib.php';

$froxlor = $argv[1] ?? '';
$dryRun = in_array('--dry-run', array_slice($argv, 2), true);

[$pdo, $db, $host, $user] = agency_db_connect($froxlor);
echo "  Database '$db' on '$host' as '$user'\n";

$changed = agency_reset_theme($pdo, $dryRun);

$prefix = $dryRun ? 'would reset' : 'reset';
if ($changed['accounts'] === 0 && $changed['default'] === 0) {
	echo "  Agency theme was not in use\n";
} else {
	echo "  $prefix {$changed['accounts']} account(s) to Froxlor's theme\n";
	if ($changed['default'] > 0) {
		echo "  $prefix panel default theme to Froxlor's theme\n";
	}
}
