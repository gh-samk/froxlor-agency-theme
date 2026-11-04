<?php

/**
 * Checks the Agency theme - without a database.
 *
 *   php tests/theme.php                        is the theme consistent in itself?
 *   php tests/theme.php /path/to/froxlor       additionally against a real Froxlor installation
 *
 * The theme hangs together in three places that know nothing of each other:
 * config.json names the assets, build/manifest.json translates them into URLs
 * for Froxlor's vite() function, theme_agency.php redirects these URLs to the
 * real files. If one of them does not fit, you only notice in the browser - as
 * a page without styles. This script checks exactly these connections, and it
 * runs the redirect once for real in PHP's built-in web server.
 *
 * The Froxlor directory must be a release (with templates/Froxlor/build/),
 * not a plain source checkout.
 */

declare(strict_types=1);

// Command line only. Should this directory ever end up below a web root, it
// must not be executable from a browser.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

$root = dirname(__DIR__);
$froxlor = $argv[1] ?? null;
$failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
	global $failures;
	echo ($ok ? '  ok      ' : '  FAIL    ') . $what . ($ok || $detail === '' ? '' : " - $detail") . "\n";
	if (!$ok) {
		$failures++;
	}
}

/** Resolves ./ and ../ in a path the way a browser does with a URL. */
function normalize(string $path): string
{
	$parts = [];
	foreach (explode('/', $path) as $part) {
		if ($part === '..') {
			array_pop($parts);
		} elseif ($part !== '.' && $part !== '') {
			$parts[] = $part;
		}
	}
	return implode('/', $parts);
}

$config = json_decode((string)file_get_contents("$root/templates/Agency/config.json"), true);
$manifest = json_decode((string)file_get_contents("$root/templates/Agency/build/manifest.json"), true);
$redirect = (string)file_get_contents("$root/theme_agency.php");
preg_match_all("/^\t'([a-z.]+)' => \[/m", $redirect, $matches);
$known = $matches[1];

echo "config.json\n";
check('is valid JSON', is_array($config));
check('variant "default" is light', ($config['variants']['default']['color-scheme'] ?? '') === 'light');
check('variant "dark" is dark', ($config['variants']['dark']['color-scheme'] ?? '') === 'dark');
check('variant "dark" is labelled "Agency (dark)"', ($config['variants']['dark']['description'] ?? '') === 'Agency (dark)');
foreach (array_keys($config['variants'] ?? []) as $variant) {
	// Froxlor splits theme and variant at the underscore and only accepts
	// [a-z0-9.-] on both sides (lib/init.php, UI::getTheme()).
	check("variant name '$variant' matches Froxlor's pattern", preg_match('/^[a-z0-9.\-]+$/i', (string)$variant) === 1);
}
check('theme directory name matches Froxlor\'s pattern', preg_match('/^[a-z0-9.\-]+$/i', 'Agency') === 1);

echo "Assets: config.json -> manifest.json -> theme_agency.php\n";
check('manifest.json is valid JSON', is_array($manifest));
foreach (['css', 'js'] as $kind) {
	foreach ($config['global'][$kind] ?? [] as $entry) {
		$key = 'templates/Agency/' . $entry;
		if ($entry === 'assets/css/custom.css') {
			check('custom.css stays the admin\'s optional hook, not in the manifest', !isset($manifest[$key]));
			check('custom.css is not shipped', !is_file("$root/templates/Agency/$entry"));
			continue;
		}
		$file = $manifest[$key]['file'] ?? null;
		check("$entry is in the manifest", is_string($file));
		if (!is_string($file)) {
			continue;
		}
		// Re-implementation of vite() in Froxlor's lib/functions.php
		$link = 'templates/Agency/build/' . $file;
		check("$entry is included as $kind", pathinfo($link, PATHINFO_EXTENSION) === $kind, pathinfo($link, PATHINFO_EXTENSION));
		$target = normalize(explode('?', $link)[0]);
		check("$entry points to theme_agency.php in Froxlor's root directory", $target === 'theme_agency.php', $target);
		parse_str((string)parse_url($link, PHP_URL_QUERY), $query);
		$name = is_string($query['file'] ?? null) ? $query['file'] : '';
		check("$entry: name '$name' is known to the redirect", in_array($name, $known, true));
		if (!str_starts_with($entry, '../')) {
			check("$entry is part of the theme", is_file("$root/templates/Agency/$entry"));
		}
	}
}
foreach (array_keys($manifest ?? []) as $key) {
	check("manifest key $key is used by config.json", in_array(substr((string)$key, strlen('templates/Agency/')), array_merge($config['global']['css'] ?? [], $config['global']['js'] ?? []), true));
}

echo "Fonts\n";
$css = (string)file_get_contents("$root/templates/Agency/assets/css/agency.css");
preg_match_all('/url\("(\.\.\/fonts\/[^"]+)"\)/', $css, $fonts);
check('agency.css embeds fonts', count($fonts[1]) > 0);
foreach ($fonts[1] as $font) {
	check("$font exists", is_file("$root/templates/Agency/assets/css/$font"));
}
check('font licence is included', is_file("$root/templates/Agency/assets/fonts/OFL.txt"));
check('no external URLs in agency.css', preg_match('#url\(\s*["\']?(?:https?:)?//#i', $css) === 0);

echo "Redirect in the web server\n";
$tmp = sys_get_temp_dir() . '/agency-theme-test-' . getmypid();
mkdir("$tmp/templates/Froxlor/build/assets", 0777, true);
mkdir("$tmp/templates/Agency/assets/css", 0777, true);
copy("$root/theme_agency.php", "$tmp/theme_agency.php");
copy("$root/templates/Agency/assets/css/agency.css", "$tmp/templates/Agency/assets/css/agency.css");
// The CLI-only files, as if the package had been cloned into a web root.
foreach (['scripts/lib.php', 'scripts/reset-theme.php', 'tests/theme.php', 'tests/install.php'] as $cliFile) {
	@mkdir(dirname("$tmp/pkg/$cliFile"), 0777, true);
	copy("$root/$cliFile", "$tmp/pkg/$cliFile");
}
$writeManifest = static function (array $data) use ($tmp): void {
	file_put_contents("$tmp/templates/Froxlor/build/manifest.json", json_encode($data));
};
$writeManifest([
	'templates/Froxlor/assets/scss/app.scss' => ['file' => 'assets/app-AbC123.css'],
	'templates/Froxlor/assets/js/app.js' => ['file' => 'assets/app-XyZ789.js'],
]);
$port = 18000 + getmypid() % 1000;
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $tmp], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$request = static function (string $path, string $method = 'GET') use ($port): array {
	$context = stream_context_create(['http' => ['method' => $method, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 2]]);
	for ($attempt = 0; $attempt < 30; $attempt++) {
		// Headers via the stream metadata: works the same on PHP 8.1 and on
		// versions in which $http_response_header is deprecated.
		$stream = @fopen("http://127.0.0.1:$port/$path", 'r', false, $context);
		if ($stream !== false) {
			$headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
			$body = (string)stream_get_contents($stream);
			fclose($stream);
			$status = (int)(explode(' ', $headers[0] ?? '')[1] ?? 0);
			$found = [];
			foreach ($headers as $header) {
				$pos = strpos($header, ':');
				if ($pos !== false) {
					$found[strtolower(substr($header, 0, $pos))] = trim(substr($header, $pos + 1));
				}
			}
			return [$status, $found['location'] ?? '', $found, $body];
		}
		usleep(100000);
	}
	return [0, '', [], ''];
};
[$status, $location, $headers] = $request('theme_agency.php?file=froxlor.css');
check('froxlor.css -> current file from Froxlor\'s manifest', $status === 302 && $location === 'templates/Froxlor/build/assets/app-AbC123.css', "$status $location");
check('redirect is cached only briefly and privately', ($headers['cache-control'] ?? '') === 'private, max-age=60', $headers['cache-control'] ?? '');
check('nosniff header is sent', ($headers['x-content-type-options'] ?? '') === 'nosniff');
[$status, $location] = $request('theme_agency.php?file=froxlor.js');
check('froxlor.js -> current file from Froxlor\'s manifest', $status === 302 && $location === 'templates/Froxlor/build/assets/app-XyZ789.js', "$status $location");
[$status, $location] = $request('theme_agency.php?file=agency.css');
check('agency.css -> own file with version marker', $status === 302 && preg_match('#^templates/Agency/assets/css/agency\.css\?v=\d+$#', $location) === 1, "$status $location");
[$status] = $request('theme_agency.php?file=../lib/userdata.inc.php');
check('unknown name -> 404, no paths from outside', $status === 404, (string)$status);
[$status, , , $body] = $request('theme_agency.php?file[]=froxlor.css');
check('array parameter -> 404 without PHP warning', $status === 404 && $body === '', "$status " . trim($body));
[$status] = $request('theme_agency.php');
check('missing parameter -> 404', $status === 404, (string)$status);
[$status, , $headers] = $request('theme_agency.php?file=froxlor.css', 'POST');
check('POST -> 405', $status === 405 && ($headers['allow'] ?? '') === 'GET, HEAD', (string)$status);
foreach (['//evil.example.com/x.css', 'https://evil.example.com/x.css', '../../../lib/userdata.inc.php.css', '/etc/passwd.css', "assets/app.css\r\nX-Injected: 1", 'assets/app-AbC123.js'] as $bad) {
	$writeManifest([
		'templates/Froxlor/assets/scss/app.scss' => ['file' => $bad],
		'templates/Froxlor/assets/js/app.js' => ['file' => 'assets/app-XyZ789.js'],
	]);
	[$status, $location] = $request('theme_agency.php?file=froxlor.css');
	check('manifest value ' . json_encode($bad) . ' is rejected (404)', $status === 404, "$status $location");
}
$writeManifest(['templates/Froxlor/assets/scss/app.scss' => ['file' => 'assets/css/app-AbC123.css']]);
[$status, $location] = $request('theme_agency.php?file=froxlor.css');
check('manifest value in a subdirectory is accepted', $status === 302 && $location === 'templates/Froxlor/build/assets/css/app-AbC123.css', "$status $location");
foreach (['scripts/lib.php', 'scripts/reset-theme.php', 'tests/theme.php', 'tests/install.php'] as $cliFile) {
	[$status, , , $body] = $request("pkg/$cliFile");
	check("$cliFile over HTTP -> 404 without running", $status === 404 && $body === '', "$status " . substr($body, 0, 80));
}
file_put_contents("$tmp/templates/Froxlor/build/manifest.json", 'not json');
[$status] = $request('theme_agency.php?file=froxlor.css');
check('broken Froxlor manifest -> 404 instead of a broken redirect', $status === 404, (string)$status);
unlink("$tmp/templates/Froxlor/build/manifest.json");
[$status] = $request('theme_agency.php?file=froxlor.css');
check('missing Froxlor manifest -> 404 instead of a broken redirect', $status === 404, (string)$status);
proc_terminate($server);
proc_close($server);
exec('rm -rf ' . escapeshellarg($tmp));

if ($froxlor !== null) {
	echo "Against Froxlor in $froxlor\n";
	$froxlorManifest = json_decode((string)@file_get_contents("$froxlor/templates/Froxlor/build/manifest.json"), true);
	check('Froxlor\'s manifest is readable', is_array($froxlorManifest));
	foreach (['templates/Froxlor/assets/scss/app.scss', 'templates/Froxlor/assets/js/app.js'] as $source) {
		check("$source is in Froxlor's manifest", isset($froxlorManifest[$source]['file']));
		check("$source is also used in theme_agency.php", str_contains($redirect, "'$source'"));
	}
	foreach (['css', 'js'] as $kind) {
		foreach ($config['global'][$kind] ?? [] as $entry) {
			if (str_starts_with($entry, '../')) {
				// lib/init.php only includes assets that exist as files
				check("$entry exists for Froxlor's file check", is_file("$froxlor/templates/" . substr($entry, 3)));
			}
		}
	}
	$images = array_merge(array_values($config['global']['img'] ?? []), ...array_map(static fn($v) => array_values($v['img'] ?? []), array_values($config['variants'] ?? [])));
	foreach (array_unique($images) as $image) {
		// lib/init.php: 'templates/<theme>/assets/img/' . <img>
		check("logo $image exists", is_file("$froxlor/" . normalize("templates/Agency/assets/img/$image")));
	}
	$froxlorConfig = json_decode((string)@file_get_contents("$froxlor/templates/Froxlor/config.json"), true);
	check('Froxlor theme has a "dark" variant (uninstall resets Agency_dark to Froxlor_dark)', isset($froxlorConfig['variants']['dark']));
}

echo $failures === 0 ? "\nAll good.\n" : "\n$failures failure(s).\n";
exit($failures === 0 ? 0 : 1);
