#!/usr/bin/env bash
#
# Removes the Agency theme from a Froxlor installation.
#
#   ./uninstall.sh /var/www/froxlor
#   ./uninstall.sh /var/www/froxlor --dry-run     change nothing, only show
#   ./uninstall.sh /var/www/froxlor --skip-db     only remove files (reset the
#                                                 theme settings by hand, see below)
#
# Every account and the panel default that still use the theme are switched
# back to Froxlor's own theme first (the dark variant to Froxlor's dark
# variant). Then the theme's files are removed, including a custom.css in
# templates/Agency/assets/css/ - back it up first if you want to keep it.

set -euo pipefail
umask 022

TARGET="${1:-}"
shift || true

DRY_RUN=0
SKIP_DB=0
for opt in "$@"; do
	case "$opt" in
		--dry-run) DRY_RUN=1 ;;
		--skip-db) SKIP_DB=1 ;;
		*) echo "Unknown option: $opt" >&2; exit 1 ;;
	esac
done

TARGET="${TARGET%/}"
if [[ -z "$TARGET" ]] || [[ ! -f "$TARGET/lib/init.php" ]]; then
	echo "Usage: ./uninstall.sh <froxlor-directory> [--dry-run] [--skip-db]" >&2
	exit 1
fi

TARGET="$(cd "$TARGET" && pwd -P)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
THEME="Agency"

# shellcheck source=scripts/privileges.sh
. "$HERE/scripts/privileges.sh"

# as_owner CMD...: run CMD as the owner of the Froxlor directory (see
# scripts/privileges.sh); as root itself only where nobody else can write.
as_owner() { "$@"; }
if [[ $EUID -eq 0 ]]; then
	bad="$(untrusted_component "$HERE/scripts/reset-theme.php" "${SUDO_UID:-}")"
	[[ -z "$bad" ]] && bad="$(untrusted_component "$HERE/scripts/lib.php" "${SUDO_UID:-}")"
	if [[ -n "$bad" ]]; then
		echo "ERROR: the package is not safe to run as root: $bad is a link or" >&2
		echo "owned/writable by another user. Clone it outside any web root (e.g. /opt)." >&2
		exit 1
	fi
	OWNER_UID="$(file_uid "$TARGET")"
	OWNER_GID="$(file_gid "$TARGET")"
	if [[ "$OWNER_UID" != "0" ]]; then
		as_owner() { drop_to "$OWNER_UID" "$OWNER_GID" "$@"; }
	else
		unsafe="$(untrusted_component "$TARGET/templates")"
		if [[ -n "$unsafe" ]]; then
			echo "ERROR: $TARGET belongs to root, but $unsafe is a link or" >&2
			echo "owned/writable by another user or group; root does not delete there." >&2
			exit 1
		fi
	fi
fi

FILES=(
	"theme_agency.php"
	"templates/$THEME"
)

manual_sql() {
	echo "    UPDATE panel_admins    SET theme = IF(theme = '${THEME}_dark', 'Froxlor_dark', 'Froxlor') WHERE theme = '$THEME' OR theme LIKE '${THEME}\\_%';"
	echo "    UPDATE panel_customers SET theme = IF(theme = '${THEME}_dark', 'Froxlor_dark', 'Froxlor') WHERE theme = '$THEME' OR theme LIKE '${THEME}\\_%';"
	echo "    UPDATE panel_settings  SET value = IF(value = '${THEME}_dark', 'Froxlor_dark', 'Froxlor') WHERE settinggroup = 'panel' AND varname = 'default_theme' AND (value = '$THEME' OR value LIKE '${THEME}\\_%');"
}

# Database first, while the theme still exists: if the reset cannot run, the
# files stay in place and nobody ends up with a missing theme (Froxlor would
# log "Theme could not be found" on every page for those accounts).
echo "Database"
if [[ $SKIP_DB -eq 1 ]]; then
	echo "  skipped (--skip-db). Reset accounts that use the theme by hand:"
	manual_sql
elif [[ -L "$TARGET/lib/userdata.inc.php" ]]; then
	echo "  ERROR: lib/userdata.inc.php is a symbolic link; it is not read. Nothing was removed." >&2
	exit 1
elif command -v php >/dev/null 2>&1 && [[ -r "$TARGET/lib/userdata.inc.php" ]]; then
	RESET_OPTS=()
	[[ $DRY_RUN -eq 1 ]] && RESET_OPTS+=(--dry-run)
	# userdata.inc.php is parsed as literal data, never executed (see
	# scripts/lib.php), so this may run as root. Its output can contain text
	# from that file and is stripped of control characters.
	if ! php "$HERE/scripts/reset-theme.php" "$TARGET" "${RESET_OPTS[@]+"${RESET_OPTS[@]}"}" </dev/null 2>&1 | sanitize_output; then
		{
			echo "  ERROR: the theme reset failed. Fix the database access, or use --skip-db"
			echo "  and reset the theme by hand:"
			manual_sql
			echo "  Nothing was removed."
		} >&2
		exit 1
	fi
else
	{
		echo "  ERROR: lib/userdata.inc.php not readable or php missing. Run as root,"
		echo "  or use --skip-db and reset the theme by hand:"
		manual_sql
		echo "  Nothing was removed."
	} >&2
	exit 1
fi

echo
echo "Files"
if [[ -f "$TARGET/templates/$THEME/assets/css/custom.css" ]]; then
	echo "  note: templates/$THEME/assets/css/custom.css is removed together with the theme"
fi
# rm -rf removes a link itself, never what it points to.
for path in "${FILES[@]}"; do
	if [[ -e "$TARGET/$path" || -L "$TARGET/$path" ]]; then
		if [[ $DRY_RUN -eq 1 ]]; then
			echo "  would remove: $path"
		else
			as_owner rm -rf -- "${TARGET:?}/$path" </dev/null
			echo "  removed: $path"
		fi
	fi
done

if [[ $DRY_RUN -eq 0 && -d "$TARGET/cache" && ! -L "$TARGET/cache" ]]; then
	as_owner find "$TARGET/cache" -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + </dev/null 2>/dev/null || true
	echo "  Twig cache cleared"
fi

echo
echo "Done."
