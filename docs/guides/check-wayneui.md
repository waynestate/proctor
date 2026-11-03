---
title: WayneUI checks
description: The checks that flag forked copies of WayneUI views and an installed WayneUI that is behind the latest release.
---

# WayneUI checks

This page covers the two PROCTOR checks that watch the WayneUI an app carries: forked copies that hide upgrades, and locked or copied WayneUI that is behind the latest release. Run `vendor/bin/proctor check` against the app path, then update the lock file or replace the copy the report names. PROCTOR marks the paper against the current syllabus, not the one the student photocopied last semester.

## wayneui-forked-views

`wayneui-forked-views` warns when forked copies of WayneUI are committed: any file under `resources/views/vendor/wayne-ui/` (the published 1.x views) or `public/vendor/wayne-ui/` (the published 1.x assets).

Those copies silently override the package, so an app keeps old layouts after a WayneUI upgrade and nobody sees it; WayneUI's MIGRATING.md says to delete them.

Only committed files count: the git-tracked files when the app path sits in a git repository and git can be run, otherwise the files on disk. Skipped at any depth: `node_modules/` and `.git/`; skipped at the top level only: `vendor/` (Composer's), so the `vendor` segment inside `resources/views/vendor/wayne-ui/` still matches.

One warning per folder, with its file count. This is a warning, not a failure: a new check must not turn a passing app into a failing one.

Fix by deleting the folder and running `git rm -r --cached` on it; if you changed the forked views on purpose, compare them with the package views first.

| Check | Rule id |
| --- | --- |
| `wayneui-forked-views` | `proctor/wayneui-forked-views` |

## wayneui-outdated

`wayneui-outdated` warns when the WayneUI an app carries is behind the latest release.

It reads the installed version from composer.lock (`waynestate/wayne-ui`), package-lock.json (`@waynestate/wayne-ui-css`, `@waynestate/wayne-ui-vue`) and committed copies of `wayne-ui.css` (the version in the banner of the first 400 bytes). It asks composer once per run for the release list (`composer show waynestate/wayne-ui --all --format=json --no-interaction`), and warns, per source, when the installed version is behind the highest stable release of the same major. Pre-releases (-dev, alpha, beta, RC) and other majors are ignored.

A locked WayneUI 1.x (`cit-web-infra/wayne-ui`) gets its own warning with no lookup: 1.x is Bootstrap 3 and predates the comparison.

Only committed files count as copies: the git-tracked files when the app path sits in a git repository and git can be run, otherwise the files on disk; skipped at any depth: `node_modules/` and `.git/`, and the top-level `vendor/`.

When the lookup cannot run (no composer on PATH, offline, or no parsable versions) the check reports one info finding with the skipped marker and nothing else, so an offline run never breaks; an app with nothing WayneUI-related runs no process at all. The skipped finding is `could not look up the latest WayneUI release (offline or composer unavailable); wayneui-outdated skipped`.

By default the check warns, not fails: being out of date must not turn a passing app into a failing one. An app that wants a hard stop on its pasted stylesheets sets `wayneui.copies` to `fail` in its composer.json, under `extra.proctor` next to `github-mirror`; the default is `warn`. In `fail` mode a behind committed copy is a failure, with a sentence naming the setting appended to its message, while findings from composer.lock and package-lock.json stay warnings; an offline run never fails in either mode, because an unknown latest is always just the info skipped finding.

Fix by updating the lock file (`composer update waynestate/wayne-ui`, or `npm update` for the npm packages) or by replacing the pasted copy from the release.

| Check | Rule id |
| --- | --- |
| `wayneui-outdated` | `proctor/wayneui-outdated` |
| `wayneui-outdated` | `proctor/wayneui-outdated/skipped` |

## Related

- [CI checks](check-ci.md)
- [Overrides](overrides.md)
