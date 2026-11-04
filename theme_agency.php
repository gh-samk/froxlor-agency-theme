<?php

/**
 * Agency theme: redirects the theme's stylesheet and script URLs to the
 * current files.
 *
 * Why a redirect instead of fixed paths: Froxlor ships its Bootstrap CSS and
 * its JavaScript with a content hash in the file name
 * (build/assets/app-DgN1KmJf.css), and the hash changes with every Froxlor
 * update. A theme that wrote these names into its own build/manifest.json
 * would be left without styles and without JavaScript after an update until
 * someone re-ran install.sh. This file instead reads Froxlor's own manifest on
 * every request.
 *
 * It is referenced from templates/Agency/build/manifest.json. Froxlor's vite()
 * function appends the "file" value found there to the theme's build path and
 * picks the HTML tag by file extension - that is why every value ends in .css
 * or .js.
 *
 * Deliberately without lib/init.php: no session, no database. This endpoint is
 * public and unauthenticated. It only reveals paths of files that the web
 * server serves publicly anyway, and it knows exactly the three names below:
 * - the requested name is looked up in a fixed allowlist, never used as a path;
 * - the redirect target is always a relative path below templates/, built from
 *   a fixed prefix plus a strictly validated file name, so it can never point
 *   to another host (no open redirect) or outside the build directory.
 */

declare(strict_types=1);

$targets = [
	'froxlor.css' => ['manifest' => 'templates/Froxlor/assets/scss/app.scss', 'ext' => 'css'],
	'froxlor.js' => ['manifest' => 'templates/Froxlor/assets/js/app.js', 'ext' => 'js'],
	'agency.css' => ['file' => 'templates/Agency/assets/css/agency.css', 'ext' => 'css'],
];

header('X-Content-Type-Options: nosniff');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
	header('Allow: GET, HEAD');
	http_response_code(405);
	exit;
}

// No "never" return type and no PHP 8-only functions in this file: it runs in
// the web server's PHP, which may be older than the CLI the installer checks.
$notFound = static function (): void {
	header('Cache-Control: no-store');
	http_response_code(404);
	exit;
};

// is_string(): "?file[]=x" yields an array, which must not be cast (warning).
$name = $_GET['file'] ?? '';
if (!is_string($name) || !isset($targets[$name])) {
	$notFound();
}
$target = $targets[$name];

$location = null;
if (isset($target['manifest'])) {
	$manifest = json_decode((string)@file_get_contents(__DIR__ . '/templates/Froxlor/build/manifest.json'), true);
	$file = is_array($manifest) ? ($manifest[$target['manifest']]['file'] ?? null) : null;
	// Only plain relative paths below build/: every segment starts with a
	// letter, digit, "_" or "-" (so no "..", no leading "/", no scheme), and
	// the extension matches what vite() was told. Froxlor writes this file
	// itself; the check keeps a damaged manifest from producing a bad redirect.
	$segment = '[A-Za-z0-9_-][A-Za-z0-9._-]*';
	if (is_string($file) && preg_match('#^(?:' . $segment . '/)*' . $segment . '\.' . $target['ext'] . '$#D', $file) === 1) {
		$location = 'templates/Froxlor/build/' . $file;
	}
} else {
	// Own files carry no hash in their name. The modification time as version
	// marker makes browsers fetch the new copy after an update of the theme -
	// Froxlor sends no Cache-Control for static files.
	$mtime = @filemtime(__DIR__ . '/' . $target['file']);
	if ($mtime !== false) {
		$location = $target['file'] . '?v=' . $mtime;
	}
}

if ($location === null) {
	$notFound();
}

// Relative to the address of this file, so installations in a subdirectory
// work too. Cached only briefly: every page asks for the redirect, but after an
// update the new address should take effect quickly.
header('Cache-Control: private, max-age=60');
header('Location: ' . $location, true, 302);
