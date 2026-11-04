<?php

/**
 * Helpers for the Agency theme's maintenance scripts (CLI only, never
 * installed into Froxlor).
 */

declare(strict_types=1);

// Command line only. Should this directory ever end up below a web root, it
// must not be executable from a browser.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/** Directory name of the theme below templates/. */
const AGENCY_THEME = 'Agency';

/**
 * Reads the variables of a userdata.inc.php without executing it.
 *
 * @return array<string,mixed> variable name => value (e.g. 'sql', 'sql_root')
 * @throws RuntimeException on anything that is not a plain literal assignment
 */
function agency_read_userdata(string $file): array
{
	// The file belongs to the panel user, the reader may be root: no links,
	// only a regular file, and the file opened must be the one checked.
	$before = @lstat($file);
	if ($before === false || is_link($file) || !is_file($file)) {
		throw new RuntimeException("$file is missing, not a regular file or a symbolic link.");
	}
	$handle = @fopen($file, 'rb');
	if ($handle === false) {
		throw new RuntimeException("$file is not readable.");
	}
	$opened = fstat($handle);
	if ($opened === false || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']) {
		fclose($handle);
		throw new RuntimeException("$file changed while it was opened.");
	}
	$code = (string)stream_get_contents($handle);
	fclose($handle);
	$tokens = array_values(array_filter(
		token_get_all($code),
		static fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)
	));
	$pos = 0;
	$vars = [];

	$peek = static function () use (&$tokens, &$pos) {
		return $tokens[$pos] ?? null;
	};
	$fail = static function (string $what) use (&$tokens, &$pos, $file): void {
		$t = $tokens[$pos] ?? null;
		$near = is_array($t) ? token_name($t[0]) . ' ' . trim($t[1]) . ' (line ' . $t[2] . ')' : (string)$t;
		throw new RuntimeException("Unsupported content in $file: expected $what near $near.");
	};
	$expect = static function (string $char) use (&$tokens, &$pos, $fail): void {
		if (($tokens[$pos] ?? null) !== $char) {
			$fail("'$char'");
		}
		$pos++;
	};

	$value = null;
	$value = static function () use (&$value, &$tokens, &$pos, $peek, $fail, $expect) {
		$t = $peek();
		if ($t === '[' || (is_array($t) && $t[0] === T_ARRAY)) {
			$close = ']';
			if ($t !== '[') {
				$pos++;
				$expect('(');
				$close = ')';
			} else {
				$pos++;
			}
			$result = [];
			while ($peek() !== $close) {
				$item = $value();
				if ($peek() === null) {
					$fail("'$close'");
				}
				if (is_array($peek()) && $peek()[0] === T_DOUBLE_ARROW) {
					$pos++;
					if (!is_int($item) && !is_string($item)) {
						$fail('a scalar array key');
					}
					$result[$item] = $value();
				} else {
					$result[] = $item;
				}
				if ($peek() === ',') {
					$pos++;
				} elseif ($peek() !== $close) {
					$fail("',' or '$close'");
				}
			}
			$pos++;
			return $result;
		}
		if ($t === '-') {
			$pos++;
			$n = $value();
			if (!is_int($n) && !is_float($n)) {
				$fail('a number');
			}
			return -$n;
		}
		if (!is_array($t)) {
			$fail('a value');
		}
		switch ($t[0]) {
			case T_CONSTANT_ENCAPSED_STRING:
				$pos++;
				$quote = $t[1][0];
				$body = substr($t[1], 1, -1);
				return $quote === "'"
					? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
					: stripcslashes($body);
			case T_START_HEREDOC:
				$pos++;
				$body = '';
				if (is_array($peek()) && $peek()[0] === T_ENCAPSED_AND_WHITESPACE) {
					$body = $peek()[1];
					$pos++;
				}
				if (!is_array($peek()) || $peek()[0] !== T_END_HEREDOC) {
					$fail('end of nowdoc/heredoc without interpolation');
				}
				$pos++;
				// The closing newline belongs to the delimiter, not to the value.
				$body = preg_replace('/\r?\n$/', '', $body);
				return str_contains($t[1], "'") ? $body : stripcslashes($body);
			case T_LNUMBER:
				$pos++;
				return intval($t[1], 0);
			case T_DNUMBER:
				$pos++;
				return (float)$t[1];
			case T_STRING:
				$word = strtolower($t[1]);
				if (in_array($word, ['true', 'false', 'null'], true)) {
					$pos++;
					return $word === 'null' ? null : $word === 'true';
				}
		}
		$fail('a literal value');
	};

	while ($peek() !== null) {
		$t = $peek();
		if (!is_array($t) || $t[0] !== T_VARIABLE) {
			$fail('a variable assignment');
		}
		$name = substr($t[1], 1);
		$pos++;
		$path = [];
		while ($peek() === '[') {
			$pos++;
			$key = $value();
			if (!is_int($key) && !is_string($key)) {
				$fail('a scalar array key');
			}
			$path[] = $key;
			$expect(']');
		}
		$expect('=');
		$v = $value();
		$expect(';');

		$ref = &$vars[$name];
		foreach ($path as $key) {
			if (!is_array($ref)) {
				$ref = [];
			}
			$ref = &$ref[$key];
		}
		$ref = $v;
		unset($ref);
	}

	return $vars;
}

/**
 * Reads the $sql block from Froxlor's lib/userdata.inc.php - without
 * executing it (see agency_read_userdata()), so no code of the panel user
 * ever runs, even when uninstall.sh is started as root.
 *
 * @return array<string, mixed>
 */
function agency_load_sql(string $froxlor): array
{
	if ($froxlor === '') {
		throw new RuntimeException('No Froxlor directory given.');
	}
	$vars = agency_read_userdata(rtrim($froxlor, '/') . '/lib/userdata.inc.php');
	$sql = $vars['sql'] ?? null;
	if (!is_array($sql) || !isset($sql['db'], $sql['user'])) {
		throw new RuntimeException('userdata.inc.php contains no usable $sql configuration.');
	}
	return $sql;
}

/** PDO MySQL attribute by name: Pdo\Mysql::ATTR_* (PHP 8.4+), else PDO::MYSQL_ATTR_* (deprecated in 8.5). */
function agency_pdo_mysql_attribute(string $name): int
{
	if (defined('Pdo\\Mysql::ATTR_' . $name)) {
		return constant('Pdo\\Mysql::ATTR_' . $name);
	}
	return constant('PDO::MYSQL_ATTR_' . $name);
}

/**
 * Builds DSN and options exactly like Froxlor's Database::getDB() (2.3.x) for
 * the unprivileged panel user: unix socket if $sql['socket'] is set,
 * otherwise host and port ($sql['port'], default 3306) plus, if
 * $sql['ssl']['caFile'] is set, the CA file and
 * $sql['ssl']['verifyServerCertificate'] (default false, as in Froxlor).
 *
 * @param array<string, mixed> $sql
 * @return array{dsn: string, user: string, password: string, options: array<int, mixed>}
 */
function agency_db_config(array $sql): array
{
	$dsn = ['charset' => 'utf8', 'dbname' => (string)$sql['db']];
	$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

	$socket = $sql['socket'] ?? null;
	if ($socket != null) {
		$dsn['unix_socket'] = (string)$socket;
	} else {
		$dsn['host'] = (string)($sql['host'] ?? 'localhost');
		$dsn['port'] = (string)($sql['port'] ?? '3306');
		$caFile = $sql['ssl']['caFile'] ?? '';
		if (!empty($caFile)) {
			$options[agency_pdo_mysql_attribute('SSL_CA')] = (string)$caFile;
			$options[agency_pdo_mysql_attribute('SSL_VERIFY_SERVER_CERT')] = (bool)($sql['ssl']['verifyServerCertificate'] ?? false);
		}
	}

	$parts = [];
	foreach ($dsn as $key => $value) {
		$parts[] = $key . '=' . $value;
	}

	return [
		'dsn' => 'mysql:' . implode(';', $parts),
		'user' => (string)$sql['user'],
		'password' => (string)($sql['password'] ?? ''),
		'options' => $options,
	];
}

/**
 * Opens a PDO connection with exactly the credentials and options Froxlor
 * itself uses (lib/userdata.inc.php), so nothing has to be configured twice
 * and no MySQL command line client is needed.
 *
 * @return array{0: PDO, 1: string, 2: string, 3: string}  PDO, database, server, user
 */
function agency_db_connect(string $froxlor): array
{
	try {
		$sql = agency_load_sql($froxlor);
		$config = agency_db_config($sql);
		// Never let the server request local files (LOAD DATA LOCAL INFILE):
		// the socket/host come from a file the panel user can edit.
		$options = $config['options'];
		$options[agency_pdo_mysql_attribute('LOCAL_INFILE')] = false;
		$pdo = new PDO($config['dsn'], $config['user'], $config['password'], $options);
	} catch (Throwable $e) {
		fwrite(STDERR, 'Connection failed: ' . $e->getMessage() . "\n");
		exit(1);
	}
	$server = ($sql['socket'] ?? null) != null ? (string)$sql['socket'] : (string)($sql['host'] ?? 'localhost');

	return [$pdo, (string)$sql['db'], $server, $config['user']];
}

/**
 * Switches every account and the panel default that use the Agency theme back
 * to Froxlor's own theme; the dark variant becomes Froxlor's dark variant so
 * nobody gets blinded.
 *
 * Froxlor stores the theme as "<directory>" for the default variant and as
 * "<directory>_<variant>" otherwise (UI::getThemes(), lib/init.php), in
 * panel_admins.theme, panel_customers.theme and in panel_settings
 * (settinggroup "panel", varname "default_theme").
 *
 * Without this, everyone still set to the theme would get Froxlor's warning
 * "Theme 'Agency' could not be found" on every page once the directory is gone.
 *
 * Portable SQL (CASE, LIKE ... ESCAPE) so the logic can be tested on SQLite.
 *
 * @param bool $dryRun  only count what would change
 * @return array{accounts: int, default: int}  number of (to be) changed rows
 */
function agency_reset_theme(PDO $pdo, bool $dryRun = false): array
{
	// "COL" = the column holding the theme. Matches "Agency" and "Agency_<variant>",
	// but not another theme that merely starts with the same letters.
	$set = "CASE WHEN COL = ? THEN 'Froxlor_dark' ELSE 'Froxlor' END";
	$match = "(COL = ? OR COL LIKE ? ESCAPE '!')";
	$matchParams = [AGENCY_THEME, AGENCY_THEME . '!_%'];

	$run = static function (string $table, string $column, string $extraWhere) use ($pdo, $dryRun, $set, $match, $matchParams): int {
		$where = str_replace('COL', "`$column`", $match) . $extraWhere;
		if ($dryRun) {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
			$stmt->execute($matchParams);
			return (int)$stmt->fetchColumn();
		}
		$stmt = $pdo->prepare("UPDATE `$table` SET `$column` = " . str_replace('COL', "`$column`", $set) . " WHERE $where");
		$stmt->execute(array_merge([AGENCY_THEME . '_dark'], $matchParams));
		return $stmt->rowCount();
	};

	return [
		'accounts' => $run('panel_admins', 'theme', '') + $run('panel_customers', 'theme', ''),
		'default' => $run('panel_settings', 'value', " AND `settinggroup` = 'panel' AND `varname` = 'default_theme'"),
	];
}
