#!/usr/bin/env bash
#
# Installs the Agency theme into an existing Froxlor 2.x installation.
#
#   ./install.sh /var/www/froxlor
#   ./install.sh /var/www/froxlor --dry-run     write nothing, only show
#
# Only new files are copied - no Froxlor file is modified. Run this script
# again after every Froxlor update. The theme needs no database changes.
#
# Started as root, the script never writes as root where a less privileged
# user could have planted links or names: if the Froxlor directory belongs to
# the panel's system user, every write is done as that user. If it belongs to
# root and nobody else can write to it, templates/ or templates/Agency/, root
# writes itself; mixed ownership is refused.

set -euo pipefail
umask 022

TARGET="${1:-}"
shift || true

DRY_RUN=0
for opt in "$@"; do
	case "$opt" in
		--dry-run) DRY_RUN=1 ;;
		*) echo "Unknown option: $opt" >&2; exit 1 ;;
	esac
done

if [[ -z "$TARGET" ]]; then
	cat >&2 <<'USAGE'
Usage: ./install.sh <froxlor-directory> [--dry-run]

Example:
    ./install.sh /var/www/froxlor
USAGE
	exit 1
fi

TARGET="${TARGET%/}"
if [[ ! -f "$TARGET/lib/init.php" ]] || [[ ! -d "$TARGET/templates/Froxlor" ]]; then
	echo "ERROR: '$TARGET' does not look like a Froxlor installation." >&2
	exit 1
fi
TARGET="$(cd "$TARGET" && pwd -P)"

if ! command -v php >/dev/null 2>&1; then
	echo "ERROR: php not found. The Agency theme requires PHP 8.1 or newer." >&2
	exit 1
fi
PHP_ID="$(php -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)"
if [[ ! "$PHP_ID" =~ ^[0-9]+$ ]] || (( PHP_ID < 80100 )); then
	echo "ERROR: PHP 8.1 or newer is required (found: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown))." >&2
	exit 1
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo "unknown")"
THEME="Agency"
DEST="$TARGET/templates/$THEME"

# Exactly these paths belong to the theme. Listed one by one instead of a
# blanket sync: this way the script can never overwrite or delete anything
# that belongs to Froxlor. templates/Agency/assets/css/custom.css is not in
# the repository and is therefore never overwritten.
FILES=(
	"theme_agency.php"
	"templates/$THEME/config.json"
	"templates/$THEME/build/manifest.json"
	"templates/$THEME/assets/css/agency.css"
	"templates/$THEME/assets/fonts/figtree-latin.woff2"
	"templates/$THEME/assets/fonts/figtree-latin-ext.woff2"
	"templates/$THEME/assets/fonts/OFL.txt"
)
for path in "${FILES[@]}"; do
	if [[ ! -f "$HERE/$path" ]] || [[ -L "$HERE/$path" ]]; then
		echo "ERROR: $path is missing from the package." >&2
		exit 1
	fi
done

# shellcheck source=scripts/privileges.sh
. "$HERE/scripts/privileges.sh"

# as_owner CMD...: run CMD as the user that does the writing (see header).
as_owner() { "$@"; }
if [[ $EUID -eq 0 ]]; then
	# Root runs this package: it must not live where someone else can change it.
	bad="$(untrusted_component "$HERE/install.sh" "${SUDO_UID:-}")"
	if [[ -n "$bad" ]]; then
		echo "ERROR: the package is not safe to run as root: $bad is a link or" >&2
		echo "owned/writable by another user. Clone it outside any web root (e.g. /opt)." >&2
		exit 1
	fi
	OWNER_UID="$(file_uid "$TARGET")"
	OWNER_GID="$(file_gid "$TARGET")"
	if [[ "$OWNER_UID" != "0" ]]; then
		as_owner() { drop_to "$OWNER_UID" "$OWNER_GID" "$@"; }
		echo "Writing as uid $OWNER_UID (owner of $TARGET)"
	else
		# Root-owned: root writes itself only if no one else can write anywhere
		# on the way - every component from / down to templates/, and every
		# directory of an existing templates/Agency/.
		unsafe="$(untrusted_component "$TARGET/templates")"
		if [[ -z "$unsafe" && -d "$DEST" && ! -L "$DEST" ]]; then
			unsafe="$(find "$DEST" -type d \( ! -user 0 -o -perm -0002 -o \( -perm -0020 ! -group 0 \) \) -print -quit 2>/dev/null)"
		fi
		if [[ -n "$unsafe" ]]; then
			echo "ERROR: $TARGET belongs to root, but $unsafe" >&2
			echo "is a link or owned/writable by another user or group. Root does not write" >&2
			echo "where a less privileged user can plant links. Either give the whole Froxlor" >&2
			echo "directory to the panel's system user (the installer then writes as that" >&2
			echo "user), or make every directory on the way owned by root and not" >&2
			echo "group/world-writable." >&2
			exit 1
		fi
		echo "Writing as root ($TARGET and templates/ are root-owned and not writable by others)"
	fi
fi

# Links where the theme's files go are never followed. Nothing in the
# package is a link, so any link here was put there by someone else.
refuse_links() {
	local found=""
	[[ -L "$TARGET/templates" ]] && found="$TARGET/templates"
	[[ -L "$TARGET/theme_agency.php" ]] && found="$TARGET/theme_agency.php"
	[[ -L "$DEST" ]] && found="$DEST"
	if [[ -z "$found" && -d "$DEST" ]]; then
		found="$(find "$DEST" -type l -print -quit)"
	fi
	if [[ -n "$found" ]]; then
		echo "ERROR: symbolic link found at $found." >&2
		echo "The theme's files are never written through links. Remove it and run again." >&2
		exit 1
	fi
}
refuse_links

echo "Agency theme $VERSION"
echo "Target: $TARGET"
[[ $DRY_RUN -eq 1 ]] && echo "(dry run, nothing is written)"
echo

# Copied with tar instead of rsync: tar is part of every base system, rsync is
# often missing on minimal servers. The archive is created from the package
# (readable by the caller, e.g. root) and unpacked by the owner of the Froxlor
# directory. Ownership and modes from the checkout are not carried over; with
# umask 022 files end up 0644 and directories 0755.
echo "Files"
if [[ $DRY_RUN -eq 1 ]]; then
	for path in "${FILES[@]}"; do
		echo "  would copy: $path"
	done
else
	( cd "$HERE" && tar cf - "${FILES[@]}" ) \
		| as_owner tar -C "$TARGET" --no-same-owner --no-same-permissions -xf -
	# Fixed modes, whatever the checkout had (tar keeps execute bits).
	as_owner chmod 0644 "${FILES[@]/#/$TARGET/}" </dev/null
	as_owner chmod 0755 "$DEST" "$DEST/build" "$DEST/assets" "$DEST/assets/css" "$DEST/assets/fonts" </dev/null
	for path in "${FILES[@]}"; do
		echo "  $path"
	done
fi

# Inheritance stubs: Froxlor looks up every page template in the active theme
# first and logs a warning on every page if it is missing there
# (UI::validateThemeTemplate). Instead of copying Froxlor's templates, the
# theme gets one file per template that only extends the original - the look
# comes from the stylesheet alone. They are generated here and not shipped in
# the repository, so the list always matches the installed Froxlor version.
# Files without the marker (hand-written ones) are left alone; generated stubs
# whose original no longer exists are removed. Like Froxlor, every directory of
# the theme gets an empty index.html against directory listings.
#
# Runs as the owner of the Froxlor directory (see as_owner). Template names
# become part of a Twig expression, so only plain names are accepted.
MARKER="generated by install.sh of the Agency theme"
read -r -d '' STUBS <<'SCRIPT' || true
set -euo pipefail
umask 022
src="$1/templates/Froxlor"
dst="$1/templates/$2"
marker="$3"
dry_run="$4"
if mv --version 2>/dev/null | grep -q GNU; then MV=(mv -fT); else MV=(mv -f); fi

valid_name() {
	[[ "$1" =~ ^[A-Za-z0-9._/-]+\.twig$ ]] && [[ ! "$1" =~ (^|/)\.\.?(/|$) ]] && [[ "$1" != /* ]]
}

generated=0; skipped=0; removed=0; planned=0
while IFS= read -r -d '' template; do
	rel="${template#"$src/"}"
	if ! valid_name "$rel"; then
		printf '  skipped (unusual file name): %q\n' "$rel"
		skipped=$((skipped + 1))
		continue
	fi
	stub="$dst/$rel"
	if [[ -L "$stub" ]] || { [[ -e "$stub" ]] && [[ ! -f "$stub" ]]; }; then
		printf '  skipped (not a regular file): %s\n' "templates/$2/$rel"
		skipped=$((skipped + 1))
		continue
	fi
	if [[ -f "$stub" ]] && ! grep -qF -- "$marker" "$stub"; then
		continue
	fi
	if [[ "$dry_run" == 1 ]]; then
		planned=$((planned + 1))
		continue
	fi
	dir="$(dirname "$stub")"
	mkdir -p "$dir"
	tmp="$(mktemp "$dir/.agency-stub.XXXXXX")"
	printf '{# %s #}\n{%% extends "Froxlor/%s" %%}\n' "$marker" "$rel" > "$tmp"
	chmod 0644 "$tmp"
	"${MV[@]}" "$tmp" "$stub"
	generated=$((generated + 1))
done < <(find "$src" -type f -name '*.twig' -print0)

if [[ "$dry_run" == 1 ]]; then
	echo "  would generate $planned template stubs in templates/$2/"
	exit 0
fi

# Only regular files are read: no links, no FIFOs.
emptied=()
while IFS= read -r -d '' stub; do
	rel="${stub#"$dst/"}"
	if [[ ! -e "$src/$rel" ]] && grep -qF -- "$marker" "$stub"; then
		rm -f -- "$stub"
		removed=$((removed + 1))
		emptied+=("$(dirname "$stub")")
	fi
done < <(find "$dst" -type f -name '*.twig' -print0)

# Directories that held only removed stubs (plus the empty index.html) go too,
# walking up towards the theme directory. Nothing else is removed.
for dir in "${emptied[@]+"${emptied[@]}"}"; do
	while [[ "$dir" != "$dst" && -d "$dir" && ! -L "$dir" ]]; do
		content="$(ls -A "$dir")"
		if [[ -z "$content" ]] || { [[ "$content" == "index.html" ]] && [[ -f "$dir/index.html" && ! -L "$dir/index.html" && ! -s "$dir/index.html" ]]; }; then
			rm -f -- "$dir/index.html"
			rmdir -- "$dir"
			dir="$(dirname "$dir")"
		else
			break
		fi
	done
done

while IFS= read -r -d '' dir; do
	if [[ ! -e "$dir/index.html" ]] && [[ ! -L "$dir/index.html" ]]; then
		: > "$dir/index.html"
	fi
done < <(find "$dst" -type d -print0)

echo "  $generated template stubs generated, $removed obsolete removed, $skipped skipped"
SCRIPT

if [[ $DRY_RUN -eq 1 && ! -d "$DEST" ]]; then
	count="$(find "$TARGET/templates/Froxlor" -type f -name '*.twig' | wc -l | tr -d ' ')"
	echo "  would generate $count template stubs in templates/$THEME/"
else
	as_owner bash -c "$STUBS" agency-stubs "$TARGET" "$THEME" "$MARKER" "$DRY_RUN" </dev/null
fi

# Twig caches compiled templates; Froxlor runs Twig with auto_reload, so this
# is only a precaution. Done as the owner, since the panel writes the cache.
if [[ $DRY_RUN -eq 0 && -d "$TARGET/cache" && ! -L "$TARGET/cache" ]]; then
	as_owner find "$TARGET/cache" -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + </dev/null 2>/dev/null || true
	echo "  Twig cache cleared"
fi

echo
echo "Done. The theme can be selected under Settings > Panel settings > Theme"
echo "(default for everyone) or in each account's own profile:"
echo "    \"$THEME\" (light) or \"$THEME (dark)\"."
echo
echo "Check that no Froxlor file was touched:"
echo "    cd $TARGET && git status"
