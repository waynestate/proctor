# MAINTAINING: who watches the watcher

How to build, test, release and keep PROCTOR current. PROCTOR is the stack's exam: `proctor check` measures an app
against CREED's version policy. If PROCTOR is wrong, every app gets the wrong grade, so it is held to the same rules
it enforces. No phones during the exam, including ours.

## What PROCTOR is and what it depends on

- `waynestate/proctor` on Packagist, a framework-free PHP CLI (`bin/proctor`). Optional single-file phar.
- Runtime dependencies: `symfony/console`, `symfony/yaml`, `composer/semver`, `nikic/php-parser`. No other stack
  package at runtime.
- **Its policy comes from CREED.** `src/Creed/` is a generated copy of CREED's reader and `policy.json`
  (namespace `Waynestate\Proctor\Creed`), with a `manifest.json` of hashes. Never hand-edit it. A `--policy <file>`
  flag points PROCTOR at any other `policy.json`, for example `vendor/waynestate/creed/policy/policy.json`.
- **Who uses it:** apps, Preflight's `proctor` component, Squire's release script (as a report), WELD, and the stack's
  own front-page deploys.

## Where it lives

Development happens on Wayne State's GitLab. This GitHub repository is a read-only release mirror: `main` and tags are
copied here automatically, and changes, issues and merge requests go to GitLab first. Nobody pushes to GitHub
directly.

## Build and test

You need PHP 8.3 or newer and Composer, or only Docker.

| Task | Command |
|---|---|
| Install | `composer install` |
| All checks (what CI runs) | `composer ci` |
| Fix code style | `composer fix` |
| Build the phar (needs Docker), Windows | `.\scripts\build-phar.ps1` |
| Build the phar (needs Docker), Linux and macOS | `scripts/build-phar.sh` |

Both phar scripts write `build/proctor.phar` (gitignored). The phar is not published anywhere yet.

With only Docker (the same line works in PowerShell and sh):

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

`composer ci` runs `composer validate --strict`, PHPUnit, Pint, PHPStan and `composer audit`.

## CI

- **GitLab:** Preflight's `php-test` (PHP 8.3, 8.4, 8.5) and `composer-audit` components, plus a `composer-ci` job.
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`.
- Green means `composer ci` passed on all three PHP versions on both.

## Refreshing the CREED copy

Whenever CREED's policy or reader changes:

```
php ../creed/bin/export.php --namespace='Waynestate\Proctor\Creed' --to=src/Creed
```

Then check it. Windows: `$env:CREED_PATH = "..\creed"; composer creed-copy:check`. Linux and macOS:
`CREED_PATH=../creed composer creed-copy:check`. The check is not part of `composer ci`, because CI has no CREED
checkout, so run it by hand after each refresh. Commit the copy with the CREED commit it came from in the message
(the manifest records `creed_version` as `unknown` when the export cannot see a tag).

## Adding or changing a check

- Each check has a stable rule id (see "Rule ids" in the README) and a test. Rule ids never get reused.
- New checks start as warnings and become failures later (warn first, then fail).
- PROCTOR owns the stack's checks. If another project grows a check, it belongs here instead.

## Releasing

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading, leaving an empty
   `## Unreleased`.
2. `composer ci` green locally, then on both pipelines for that commit. Refresh the CREED copy first if CREED moved.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on GitLab. The mirror carries the tag to GitHub, and Packagist picks it up from there.
5. A release needs a maintainer's approval. Never move or delete a published tag.
6. Consumers pin `^0.x`, so a pre-1.0 minor (0.2 to 0.3) is opt-in for them; tell them in the CHANGELOG.

## The front page

`site/` is the static front page, for `https://proctor.apps.wayne.edu/` on Citadel. It is served through a small
raw-PHP `deploy/` layer generated with Squire (one per stack project); regenerate that layer, do not hand-edit it.

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, tokens, mirrors, deploy
access): ask Enterprise Applications.
