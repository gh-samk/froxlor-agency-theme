# Changelog

## 1.0.0

Initial public release.

* Agency theme for Froxlor 2.x in two variants: "Agency" (light) and
  "Agency (dark)". CSS only, on top of Froxlor's own Bootstrap build.
* `install.sh` generates one inheritance stub per Froxlor template, so the
  theme follows the installed Froxlor version without template copies.
* `theme_agency.php` resolves Froxlor's hashed asset names at request time, so
  a Froxlor update does not leave the theme without styles.
* `uninstall.sh` switches every account and the panel default back to
  Froxlor's theme (dark variant to Froxlor's dark variant) before removing
  the files.
* Figtree typeface served locally (SIL Open Font License).
