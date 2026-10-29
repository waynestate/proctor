# Changelog

## Unreleased

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
