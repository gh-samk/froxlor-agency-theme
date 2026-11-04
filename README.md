# Agency theme for Froxlor 2.x

A modern, calm light/dark look for the whole [Froxlor](https://froxlor.org)
2.x interface - login, admin area and customer area. CSS only: no Froxlor file
is modified and no Froxlor template is copied.

**Version 1.0.0** - tested against Froxlor 2.3.13 and 2.3.16.

The theme changes the look, not the way Froxlor works: same menus, same pages,
same texts. It is selectable like Froxlor's own themes, in two variants:

| Shown in Froxlor as | Stored value | Colour scheme |
|---|---|---|
| Agency | `Agency` | light |
| Agency (dark) | `Agency_dark` | dark |

Froxlor lists the default variant of a theme under its directory name, so the
light variant appears as "Agency"; its config label is "Agency (light)".

## Requirements

* Froxlor 2.3.x (2.x architecture: themes in `templates/<name>/config.json`,
  assets resolved through `templates/<name>/build/manifest.json`)
* PHP 8.1 or newer on the command line (checked by `install.sh`). The single
  PHP file served by the web server, `theme_agency.php`, uses no PHP 8-only
  syntax.
* bash, tar, find, mktemp, tr (base system); `setsid` and `setpriv` or
  `runuser` (util-linux) when the scripts are run as root
* Ownership of the Froxlor directory: either the panel's system user owns it
  (as Froxlor's installation guide sets it up), or root owns the directory,
  `templates/` and `templates/Agency/` and no other user or group can write
  there - see "Security model".
* For uninstalling: Froxlor's `lib/userdata.inc.php` and the PHP PDO MySQL
  driver, which Froxlor needs anyway.

## Installation

As root, with the package cloned **outside any web root** (for example
below `/opt` or `/root`, never inside the Froxlor directory or a customer's
document root):

```bash
cd /opt
git clone https://github.com/lichtsignale-sk/froxlor-agency-theme.git
cd froxlor-agency-theme
./install.sh /var/www/froxlor
```

Started as root, the installer writes as the owner of the Froxlor directory
if that is the panel's system user, and as root only if the directory is
root-owned and not writable by anyone else. It can also be run directly as
the owner.

See first what would happen:

```bash
./install.sh /var/www/froxlor --dry-run
```

The installer copies the theme's files, generates the template stubs (see
below) and clears Froxlor's Twig cache. It does not touch the database.

The theme is then available, but not switched on:

* **for everyone**: Settings > Panel settings > Theme: "Agency" or
  "Agency (dark)". This applies to the login page and to new accounts.
  Froxlor stores a theme with every existing account (default "Froxlor"), so
  existing accounts are switched in their own profile, or by SQL:
  `UPDATE panel_admins SET theme = 'Agency' WHERE theme = 'Froxlor';` and the
  same for `panel_customers`. If "Allow theme change" is switched off for
  admins or customers, Froxlor itself applies the new default to all of them.
* **just for yourself**: Profile > Change theme.

### Update

Run the installer again - it is idempotent:

```bash
git pull && ./install.sh /var/www/froxlor
```

**Run `install.sh` again after every Froxlor update.** An update cannot
overwrite the theme's files, because Froxlor does not know them; but an update
that replaces the whole directory removes unknown files, and a new Froxlor
version may add or remove templates, which the stubs must follow. The re-run
is cheap and takes care of both.

### Uninstall

```bash
./uninstall.sh /var/www/froxlor              # reset accounts, then remove the files
./uninstall.sh /var/www/froxlor --dry-run    # only show what would happen
./uninstall.sh /var/www/froxlor --skip-db    # only remove the files, reset by hand
```

Every admin and customer account and the panel default that still use the
theme are switched back to Froxlor's own theme first - `Agency` becomes
`Froxlor`, `Agency_dark` becomes `Froxlor_dark`, so nobody gets blinded.
Without this, Froxlor would log "Theme 'Agency' could not be found" on every
page for those accounts. If the database cannot be reached, nothing is removed
and the script prints the SQL to run by hand.

A `templates/Agency/assets/css/custom.css` is removed together with the
theme - back it up first if you want to keep it.

## Configuration

There are no settings. Own adjustments go into
`templates/Agency/assets/css/custom.css`: Froxlor includes it after the
theme's stylesheet when it exists, and `install.sh` never creates or
overwrites it. (Froxlor's `templates/Froxlor/assets/css/custom.css` only
applies to Froxlor's own theme.)

The logo follows Froxlor's own rules: the logo settings under Settings >
Panel settings apply, and a `templates/Agency/assets/img/logo_custom.png`
(plus optional `logo_custom_login.png`) replaces the logo for this theme.
Without either, the theme uses Froxlor's logos (`logo.png` on light,
`logo_white.png` on dark).

## How it works without patching Froxlor

Froxlor 2.x provides the hook points by convention; the theme uses only these,
verified against the 2.3.13 and 2.3.16 sources:

| What | How | Where in Froxlor |
|---|---|---|
| Theme | every directory under `templates/` with a `config.json` | `UI::getThemes()`, `lib/init.php` |
| Variants | `<theme>_<variant>`, both parts `[a-z0-9.-]` | `lib/init.php`, `UI::getTheme()` |
| Assets | `config.json` lists CSS/JS; only existing files are used | `lib/init.php` (`theme_assets`) |
| Asset URLs | `templates/<theme>/build/manifest.json`, read by `vite()` | `lib/functions.php` |
| Templates | looked up in the active theme first, then in `Froxlor/` | `UI::validateThemeTemplate()` |

**Template stubs.** All of Froxlor's page templates extend
`Froxlor/base.html.twig` by absolute path, and that file includes the
stylesheets of the active theme. The theme therefore needs no templates of its
own - only a stylesheet on top of Froxlor's Bootstrap build that mostly
re-maps Bootstrap's CSS variables. But Froxlor looks up each page template in
the active theme first and logs a warning on every page if it is missing
there. `install.sh` therefore creates, for every Froxlor template, a file that
only inherits from the original:

```twig
{# generated by install.sh of the Agency theme #}
{% extends "Froxlor/user/profile.html.twig" %}
```

They are generated at install time from the installed Froxlor, so the list
always matches its version. Stubs whose original disappeared are removed on
the next run; files without the marker line (written by hand) are never
touched.

**Why `theme_agency.php`.** Froxlor ships its CSS and JavaScript with a
content hash in the file name that changes with every release. If the theme's
manifest contained these names, the interface would have neither styles nor
JavaScript after a Froxlor update until someone re-ran `install.sh`. The
theme's manifest therefore points to `theme_agency.php` in Froxlor's root
directory, which reads Froxlor's current manifest on every request and
redirects to the current file. `vite()` picks the HTML tag by file extension,
which is why each URL ends in `.css` or `.js`
(`theme_agency.php?file=froxlor.css`).

**Design.** Typeface Figtree (SIL Open Font License, `templates/Agency/assets/fonts/OFL.txt`),
served locally - no requests to Google, and the interface looks the same even
when the server has no internet connection. Colours derived in OKLCH, all
text at least 4.5:1 contrast in light and dark, visible focus rings, motion
only as feedback and switched off under `prefers-reduced-motion`.

Pages added by other extensions look right with this theme as long as they
take their colours from Bootstrap's CSS variables; the theme contains no
styles for any particular extension.

## Security model

* **No Froxlor file is changed.** The installer works from an explicit list of
  paths and only ever writes `theme_agency.php` and below `templates/Agency/`
  (plus clearing `cache/`). `git status` in the Froxlor directory shows only
  untracked files.
* **Root never writes where a less privileged user can plant links.**
  Started as root, `install.sh` looks at the owner of the Froxlor directory:
  * owned by another user (the panel's system user, the usual setup): every
    write (unpacking, stubs, `index.html` files, cache) is done as that user.
    Each such command runs via `setsid --wait setpriv --reuid --regid
    --init-groups --reset-env` (or `runuser` with `env -i`): in a new session
    without root's controlling terminal (no keystroke injection via
    `TIOCSTI`), with a clean environment, stdin from `/dev/null` (or the tar
    pipe), all descriptors above 2 closed, and its output passed through a
    filter that strips control characters before it reaches root's terminal;
  * owned by root: root writes itself, but only if every path component from
    `/` down to `templates/` is no symbolic link, is owned by root and is
    neither world-writable nor group-writable by a group other than root's
    (directories with the sticky bit, like `/tmp`, are accepted), and the same
    holds for every directory of an existing `templates/Agency/`. Otherwise
    (e.g. `templates/Agency/` handed to the panel user while the rest belongs
    to root) the installer refuses with a message naming the path.

  Run as root, both scripts also refuse to run from a package location that is
  a link or that someone other than root (or the `sudo` caller) can modify.

  `uninstall.sh` removes files as the owner of the Froxlor directory in the
  same way. In addition:
  * the installer refuses to run if `templates/Agency`, anything below it or
    `theme_agency.php` is a symbolic link, and never writes through one;
  * stubs are written to a temporary file in the target directory and renamed
    into place; existing non-regular files (links, FIFOs, directories) at a
    stub path are skipped, and only regular files are read;
  * a Froxlor template is only turned into a stub if its relative name matches
    `^[A-Za-z0-9._/-]+\.twig$` (no quotes, newlines or `..`), because the name
    becomes part of a Twig expression;
  * `umask 022` and explicit `chmod`: files are 0644 and directories 0755 in
    the web root, whatever modes the checkout had;
  * every directory of the theme gets an empty `index.html`, like Froxlor's own
    template directories.
* **CLI-only files.** `scripts/*.php` and `tests/*.php` answer any non-CLI
  request with 404 and exit immediately, in case the package does end up
  below a web root.
* **`theme_agency.php` is public and unauthenticated** - the browser loads it
  for the login page too. It is kept minimal:
  * no `lib/init.php`, no session, no database;
  * the `file` parameter is looked up in a fixed allowlist of three names
    (`froxlor.css`, `froxlor.js`, `agency.css`) and never used as a path; any
    other value, including arrays, gives 404;
  * the redirect target is always a relative path built from a fixed prefix
    (`templates/Froxlor/build/` or the theme's own stylesheet). The file name
    read from Froxlor's manifest must consist of path segments that start with
    a letter, digit, `_` or `-` and end in the expected extension - no `..`,
    no leading `/`, no scheme, no other host (no open redirect, no header
    injection);
  * only `GET` and `HEAD` are answered (`405` otherwise); responses carry
    `X-Content-Type-Options: nosniff`; redirects are cached privately for 60
    seconds, 404s not at all.
  It reveals nothing beyond the URLs of files the web server serves publicly
  anyway.
* **Uninstall** connects with Froxlor's own credentials from
  `lib/userdata.inc.php`, building DSN and options exactly like Froxlor's
  `Database` class: `$sql['socket']` as unix socket if set, otherwise
  `$sql['host']` and `$sql['port']` (default 3306) with the TLS options from
  `$sql['ssl']['caFile']` and `$sql['ssl']['verifyServerCertificate']`.
  `userdata.inc.php` belongs to the panel user and is **never executed**: it
  is tokenized and only plain literal assignments are accepted (arrays,
  strings, nowdoc/heredoc without interpolation, numbers, booleans, null) -
  exactly what Froxlor's installer writes. Anything else (function calls,
  interpolation, includes, backticks) aborts the reset. A symbolic link at
  that path is refused, and the file opened must be the one checked. `LOAD
  DATA LOCAL INFILE` is disabled on the connection. Messages that contain text
  from the file are stripped of control characters. The reset changes only
  `theme` values equal to `Agency` or `Agency_<variant>` and the
  `panel.default_theme` setting, using prepared statements.
* Since Froxlor 2.3.14 the theme value sent to the API is checked against the
  installed themes (`UI::getThemes()`). "Agency" and "Agency_dark" are on that
  list as long as the theme is installed; after uninstalling, Froxlor would
  reject them, which is one more reason the uninstaller resets them.

## Compatibility

* Froxlor 2.3.13 to 2.3.16: the theme-related code (`lib/init.php`, `vite()`,
  `UI::getThemes()`, `UI::validateThemeTemplate()`, `templates/Froxlor/config.json`)
  is identical, except for the theme allowlist added in 2.3.14, which accepts
  the theme.
* Future 2.x releases (2.4): no version checks. As long as themes, `vite()`
  and the template lookup keep working the same way, the theme keeps working;
  re-running `install.sh` adapts the stubs to added or removed templates.
  `php tests/theme.php /var/www/froxlor` checks the theme against an
  installed Froxlor after an update.
* Froxlor's `main` branch (version 3, a rewrite) is not supported.

## Tests

No database server and no Froxlor installation needed:

```bash
php tests/theme.php      # config.json, manifest and redirect fit together; runs the redirect in PHP's built-in web server
php tests/install.php    # install.sh/uninstall.sh against a fake Froxlor in a temp dir (incl. planted
                         # links, hostile file names, FIFOs, loose modes), theme reset on SQLite,
                         # DSN/TLS options from a fake userdata.inc.php, userdata parser (never executes)
```

With the path of a Froxlor release as argument, `tests/theme.php` additionally
checks that Froxlor's manifest has the expected entries and the logos are
where the theme expects them, and `tests/install.php` uses that Froxlor's real
template tree (read only; everything is written to a temporary directory):

```bash
php tests/theme.php /var/www/froxlor
php tests/install.php /var/www/froxlor
```

`tests/install.php` needs the `pdo_sqlite` extension for the reset part and
`pdo_mysql` for the TLS option checks, and skips those parts otherwise. The
tests run as a normal user or as root; as root, `tests/install.php`
additionally checks which user writes (root-owned, group/world-writable,
foreign-owned and user-owned Froxlor directories and parent directories,
using uid 65534) and that a dropped command has its own session, a clean
environment, no extra descriptors and filtered output - do that in a
disposable Linux container. `tests/` is not installed into Froxlor.

## Layout

```
install.sh                               installation and update
uninstall.sh                             reset accounts, remove files
theme_agency.php                         redirects the theme's asset URLs to Froxlor's current files
templates/Agency/config.json             theme: variants, assets, logos
templates/Agency/build/manifest.json     translates the assets for Froxlor's vite()
templates/Agency/assets/css/agency.css   the stylesheet
templates/Agency/assets/fonts/           Figtree (woff2) and its licence (OFL.txt)
scripts/lib.php                          userdata parser, database connection, theme reset (CLI only)
scripts/privileges.sh                    privilege drop and path checks for install.sh/uninstall.sh
scripts/reset-theme.php                  theme reset, called by uninstall.sh
docs/PRODUCT.md                          design principles
tests/                                   test scripts, see above
```

Installed into Froxlor: `theme_agency.php` and `templates/Agency/` (the files
above plus the generated stubs and empty `index.html` files).

## Design decisions

* **CSS only, no template copies.** Copied templates would have to be merged
  by hand after every Froxlor update. Re-mapping Bootstrap's variables keeps
  Froxlor's markup and behaviour untouched, and Froxlor's own theme remains a
  fully working fallback.
* **Stubs are generated, not shipped.** Shipping them would tie the theme to
  one Froxlor version's template list.
* **A redirect instead of a fixed manifest.** See above; it removes the need
  to re-run the installer just because Froxlor's asset hashes changed.
* **No settings, no database tables.** The only database access is the reset
  on uninstall.

## License

GPL-2.0-or-later, like Froxlor - see [LICENSE](LICENSE).
Copyright (C) Samuel Kümmel.

The Figtree typeface is licensed under the SIL Open Font License 1.1, see
`templates/Agency/assets/fonts/OFL.txt`.
