# Changelog

## Unreleased

- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.
- Changed: composer-audit fails when the audit runs but its stdout is not parseable JSON (rule id `proctor/composer-audit/could-not-run`), with a message that starts with `not audited:` and carries the first stderr line; a green run that audited nothing is worse than a red one.

- Added: composer-config warns when composer.json switches Composer's dependency policy off: `config.policy` set to `false` (rule id `proctor/composer-config/policy-off`), `policy.malware` set to `false` (rule id `proctor/composer-config/policy-malware-off`) or `policy.advisories` set to `false` (rule id `proctor/composer-config/policy-advisories-off`). A warning, never a fail.
- Added: ci-pipelines warns when the pipeline environment switches Composer's audit or blocking policy off: `COMPOSER_NO_AUDIT` or `COMPOSER_NO_BLOCKING` set to anything but an empty string, `0` or `false`, or `COMPOSER_AUDIT_ABANDONED` set to `ignore` (rule id `proctor/ci-pipelines/composer-audit-off`). A warning, never a fail.

## 0.4.0 (2026-11-03)

- This is a pre-1.0 minor: consumers that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. The new checks warn and never fail.

- Added: composer-audit warns when composer.json carries audit ignore entries, reporting only their count so they move to the dated override file (rule id `proctor/composer-audit/ignore-entries`). A warning, never a fail.
- Added: overrides warns when an active entry in policy-overrides.json matches no failure (rule id `proctor/overrides/unused`), so a stale approval is removed instead of rotting. A warning, never a fail.
- Added: unbounded-constraints warns on a repository URL that is plain http, because a network attacker can then change what Composer downloads (rule id `proctor/unbounded-constraints/insecure-url`). A warning, never a fail.
- Added: unbounded-constraints warns when composer.json sets minimum-stability below stable (dev, alpha, beta or rc), because every package may then resolve to a pre-release (rule id `proctor/unbounded-constraints/minimum-stability`). A warning, never a fail.
- Fixed: composer-audit no longer passes when Composer's own audit failed: a non-zero exit code with no advisory, ignored-advisory or abandoned finding in the JSON is now a fail (rule id `proctor/composer-audit/exit-code`).
- Added: preflight-pinned warns when a Preflight component is included at a ref that is not a commit SHA, or when an app overrides the script of an included Preflight job.
- Added: lock-committed warns when composer.lock content-hash differs from composer.json.
- Fixed: the report prints raw, so a package name or constraint that looks like a formatting tag is shown as written.
- Fixed: BEST_PRACTICES tags match the code (nine stale "Not yet" tags re-tagged, the pipeline claim in 3.1 corrected).
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.
- Fixed: the BEST_PRACTICES gap list marks the items already built.
- Fixed: BEST_PRACTICES tags for 4.5, 4.7, 5.6, 6.4 and 8.3 match the code.
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.
- Fixed: BEST_PRACTICES tags for 1.3 and 8.1 match the code.

## 0.3.1 (2026-11-02)

- Fixed: mysql56 no longer warns on HTTP ->json() calls, or on long strings that are not indexed or unique.
- Changed: composer audit runs with --no-plugins --no-interaction and a minimal explicit environment (PATH plus the variables composer needs to start), so inherited COMPOSER_* switches no longer reach it.
- Fixed: text output strips terminal control characters from app-supplied strings (finding messages, override reasons and approvers); each one prints as a visible ?.

## 0.3.0 (2026-11-01)

- Added: wayneui-forked-views warns when forked WayneUI views or assets are committed in the app.
- Added: wayneui-outdated warns when the locked or copied WayneUI is behind the latest release. A new opt-in setting, `extra.proctor."wayneui.copies"`, can make a stale committed `wayne-ui.css` copy fail instead of warn.
- Added: instant-client-committed warns when Oracle Instant Client (instantclient zips or folders, libclntsh and similar libraries) is committed to the repo, with its size and the git rm fix. A warning, not a failure.
- Fixed: a wildcard package ignore pattern (for example laravel/*) now hides audit findings the way Composer does.

## 0.2.8 (2026-10-30)

- Fixed: composer-audit findings named every advisory "unknown". PROCTOR now reads Composer's real audit JSON, and each finding reads `<package> <version>: <CVE or advisory id> <title> (<severity>)`; the JSON report carries the advisory details.
- Fixed: the app's ignore list matched nothing. `config.policy.advisories.ignore-id`, `config.policy.advisories.ignore` (by package) and the legacy `config.audit.ignore` are read in both their list and map shapes, matching any of the advisory's ids.
- Fixed: a package ignore rule hides findings only the way Composer would: not when it sets `on-audit: false` (a block-only unblock, such as Booster's temporary one), and, when it carries a `constraint`, only for locked versions that satisfy it. Wildcard package patterns are not matched yet, so those findings stay visible.

- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire, and SQUIRE_PROCTOR=enforce can stop a release on failing checks).
- Changed: the deploy layer's Squire kit is refreshed to 0.3.4 (the local .env.squire file is now git-ignored).

## 0.2.7 (2026-10-29)

- Added: ci-pipelines skips the GitHub workflow for a private stack package that sets extra.proctor.github-mirror to false.
- Changed: project names written as words except acronyms.
- Fixed: stale capability claims in the site and docs.
- Added: agent-ready site (prompt blocks, Markdown twins, llms.txt), from Squire's kit.

## 0.2.6 (2026-10-29)

- Changed: the proof page moves to a private site that needs a Wayne State sign-in.
- `portability`: stops flagging Laravel's generated cache. In a Laravel app (an `artisan` file at the project root) the top-level `bootstrap/cache/` directory is now skipped, so the files `php artisan optimize` writes there (config.php, routes-v7.php, services.php, packages.php, events.php) no longer raise warnings and hide real findings. A raw-PHP app that keeps its own `bootstrap/cache/` folder is scanned as before.

## 0.2.5 (2026-10-29)

- CREED reader re-exported from CREED 0.1.2: versions older than the oldest listed line are now end of life instead of refused.

## 0.2.4 (2026-10-29)

### Fixed

- A scan of a large app no longer exits 255 with "Allowed memory size exhausted": each file's syntax tree is dropped before the next file is parsed, and `bin/proctor` raises a memory limit below 512M to 512M (a higher limit or -1 is left alone).
- Reports carry the real version: JSON, SARIF and text read the installed `waynestate/proctor` version from Composer, with `0.2.3` as the fallback, instead of the stale `0.1.0`.

## 0.2.3 (2026-10-29)

- `overrides` guards (PROCTOR-6, PROCTOR-33): an entry in policy-overrides.json that names a check that is not registered in the run, or names the `overrides` check itself, is now a tool error (exit 2) instead of being silently accepted; the valid ids are built from the registered checks. An active override that expires within 14 days of `--today` (the expiry day counts) now warns (`proctor/overrides/expiring`); a warning never changes the exit code.

## 0.2.2 (2026-10-29)

- `composer-config`: new check (PROCTOR-17, PROCTOR-18): warns when composer.json sets `allow-plugins` to `true`, `secure-http` to `false`, `lock` to `false` or `audit.block-insecure` to `false`, and notes when `require.php` is set without `config.platform.php`. Warning and info only: never a fail, so an app that passes today keeps passing.

## 0.2.1 (2026-10-29)

- PHPStan now includes Pinstripe's raw preset (`vendor/waynestate/pinstripe/phpstan/raw.neon`) instead of a hand copy of its parameters, so level 8, the ignore-reporting flags and the deprecation checks follow the coding standard from one place. PROCTOR keeps only its own paths and PHP version range. The new deprecation rules caught one call: `bin/proctor` now registers its command with `Application::addCommand()`, because `add()` is deprecated since Symfony 7.4.
- The CREED policy now resolves without `--policy`: the option, then the `PROCTOR_POLICY` environment variable, then the app's `vendor/waynestate/creed/policy/policy.json` (when at least as fresh as the bundled copy), then the policy bundled with PROCTOR. Every report names the policy in use (origin, path, `refreshed_on`, age in days); the `json` format gains a `policy` object and the `text` format a policy line.
- `policy-age`: new warning check (`proctor/policy-age`): warns when the policy in use is more than 60 days old, or when a non-bundled policy has no `refreshed_on` date. Warning only: an old policy never fails a run.
- An app with no composer.json now gets a verdict (php-floor fails) instead of exit 2.

## 0.2.0 (2026-10-29)

- `repo-url-credentials`: new warning check (D-034): flags credentials written into package source URLs in
  composer.json repository entries, composer.lock source/dist URLs, and .npmrc auth lines. The secret is masked as
  `***` in the message. Fix by moving the token to `auth.json`, which is gitignored, or a CI variable, and using
  one registry URL without credentials.
- Rule ids: every finding now carries a stable, namespaced rule id (`proctor/<check-id>` for a check's default
  finding, `proctor/<check-id>/<rule>` for a named rule). It is exposed additively as `rule_id` in the `json`
  format, as `ruleId` in `sarif`, and as `check_name` in `codequality` (previously the check id). The full set of
  45 rule ids is documented in the README; `tests/RuleIdContractTest.php` keeps the table in step with the checks.
- `portability`: PHP files are matched on the syntax tree (nikic/php-parser with names resolved) instead of line by
  line regular expressions. Comments and strings no longer trigger a rule, calls split over lines are caught,
  and `use function ... as ...` aliases resolve. Rules live as data in `Waynestate\Proctor\Code\BannedPatterns`.
- `portability`: a call through a variable (`$f()`) is reported as an info "cannot analyse" note, once per file.
- `portability`: the parse-error warning now names the file ("could not parse src/Foo.php: ...").
- Docs: `BEST_PRACTICES.md` names `Policy::fromFile()` instead of the deleted `JsonFilePolicySource`.
- `vendor/bin/proctor` now works when PROCTOR is installed as a dependency (it finds the host app's autoloader).

## 0.1.0 (2026-10-27)

- First tagged release.
