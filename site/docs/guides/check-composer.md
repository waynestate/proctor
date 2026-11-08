---
title: "Composer checks | PROCTOR docs"
description: "The checks that read composer.json and composer.lock, from unbounded constraints and the committed lock to audit results and the Composer version on PATH."
url: https://proctor.apps.wayne.edu/docs/guides/check-composer.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Composer checks[#](https://proctor.apps.wayne.edu/docs/guides/check-composer.html#composer-checks)

This page covers the seven PROCTOR checks that read composer.json and composer.lock: unbounded constraints, development-only packages in require, unsafe composer settings, the committed lock, audit results, the Composer version on PATH, and credentials in repository URLs. Run `vendor/bin/proctor check` against the app path, then fix the finding the report names. The proctor checks the pens before it checks the answers.

## unbounded-constraints[#](https://proctor.apps.wayne.edu/docs/guides/check-composer.html#unbounded-constraints)

`unbounded-constraints` fails for every runtime constraint that is `*`, names a `dev-` branch, carries `@dev`, has a lower bound with no upper bound, or matches the policy's `banned_constraints`. Each unbounded constraint is its own failure (`proctor/unbounded-constraints/unbounded`), naming the package and the offending constraint.

Three more rules warn, and never fail:

- A `path` or `vcs` repository (`proctor/unbounded-constraints/repository`).
- A `minimum-stability` below stable (`proctor/unbounded-constraints/minimum-stability`).
- A repository URL that is plain http (`proctor/unbounded-constraints/insecure-url`).

`require-dev`, `php`, `ext-*` and `lib-*` are not checked. With nothing to flag it passes: `all require constraints are bounded`. No open-ended answers in this hall.

| Check | Rule id |
| --- | --- |
| `unbounded-constraints` | `proctor/unbounded-constraints` |
| `unbounded-constraints` | `proctor/unbounded-constraints/unbounded` |
| `unbounded-constraints` | `proctor/unbounded-constraints/repository` |
| `unbounded-constraints` | `proctor/unbounded-constraints/minimum-stability` |
| `unbounded-constraints` | `proctor/unbounded-constraints/insecure-url` |

## dev-only-packages[#](https://proctor.apps.wayne.edu/docs/guides/check-composer.html#dev-only-packages)

`dev-only-packages` looks at the `require` block of composer.json, never `require-dev`, and flags the tools that belong in `require-dev` because putting them in `require` ships them to production.

Two rules report, and neither is in the deploy gate:

- `exposes-internals` fails for Laravel Boost and both spellings of Debugbar (`barryvdh/laravel-debugbar`, `fruitcake/laravel-debugbar`), because a debug panel or a Boost helper installed in production exposes app internals. It warns for Laravel Telescope, which may stay in `require` only if the app registers it for local use as Laravel's Telescope docs describe.
- `test-tool` warns for phpunit, Pest, Mockery, Sail, Pail and Collision, because a test or development tool in `require` installs in production where it is not needed.

Each finding names the package and the fix: move it to `require-dev` with `composer remove <package> && composer require --dev <package>`. With none of them in `require` it passes: `no development tool is in require`.

| Check | Rule id |
| --- | --- |
| `dev-only-packages` | `proctor/dev-only-packages` |
| `dev-only-packages` | `proctor/dev-only-packages/exposes-internals` |
| `dev-only-packages` | `proctor/dev-only-packages/test-tool` |

## composer-config[#](https://proctor.apps.wayne.edu/docs/guides/check-composer.html#composer-config)

`composer-config` warns when the `config` block of composer.json opens a door Composer closes by default:

- `allow-plugins` set to `true`: any plugin may run code during install (`proctor/composer-config/allow-plugins-all`).
- `secure-http` set to `false`: Composer may use plain HTTP for repositories (`proctor/composer-config/secure-http-off`).
- `lock` set to `false`: composer.json can change without a new lock (`proctor/composer-config/lock-off`).
- `audit.block-insecure` set to `false`: insecure versions are not blocked (`proctor/composer-config/audit-blocking-off`).
- `config.policy` set to `false`: Composer enforces no dependency policy (`proctor/composer-config/policy-off`).
- `policy.malware` set to `false`: malware results are not enforced (`proctor/composer-config/policy-malware-off`).
- `policy.advisories` set to `false`: advisories are not enforced (`proctor/composer-config/policy-advisories-off`).

It notes, as an info finding, when `require.php` is set without `config.platform.php` (`proctor/composer-config/platform-php-unset`): the lock then resolves against the local PHP. Warning and info only, never a fail, so an app that passes today keeps passing. With nothing to flag it passes: `no unsafe values in the composer config block`.

| Check | Rule id |
| --- | --- |
| `composer-config` | `proctor/composer-config` |
| `composer-config` | `proctor/composer-config/allow-plugins-all` |
| `composer-config` | `proctor/composer-config/secure-http-off` |
| `composer-config` | `proctor/composer-config/lock-off` |
| `composer-config` | `proctor/composer-config/audit-blocking-off` |
| `composer-config` | `proctor/composer-config/policy-off` |
| `composer-config` | `proctor/composer-config/policy-malware-off` |
| `composer-config` | `proctor/composer-config/policy-advisories-off` |
| `composer-config` | `proctor/composer-config/platform-php-unset` |

## lock-committed[#](https://proctor.apps.wayne.edu/docs/guides/check-composer.html#lock-committed)

`lock-committed` fails when composer.lock is missing from the app path. When the lock is present and its `content-hash` is a real 32-hex hash, it warns (`proctor/lock-committed/out-of-sync`) when the hash no longer matches composer.json, so a stale lock that still installs is visible instead of silent; the warning never changes the exit code.

| Check | Rule id |
| --- | --- |
| `lock-committed` | `proctor/lock-committed` |
| `lock-committed` | `proctor/lock-committed/out-of-sync` |

## composer-audit[#](https://proctor.apps.wayne.edu/docs/guides/check-composer.html#composer-audit)

`composer-audit` runs `composer audit --format=json --locked --no-plugins --no-interaction --abandoned=<policy>` against the app path and grades the result:

- Advisories at or above the policy's `audit_severity_threshold` fail; lower ones warn (`proctor/composer-audit/advisory`). Each finding names the package, the version locked in composer.lock, the advisory's CVE (or its advisory id when there is no CVE), its title and its severity.
- An advisory on a package that composer.lock lists only in `packages-dev` is a warning at any severity, with the message ending in ` [dev dependency]` after the severity in parentheses. A name in both the `packages` and `packages-dev` lists counts as runtime, and a name the lock does not list at all is graded as runtime too.
- An advisory the app ignores in its own composer.json (`config.policy.advisories.ignore-id`, `config.policy.advisories.ignore`, or the legacy `config.audit.ignore`) is a warning, not a fail, and so is anything composer has already moved to its ignored section.
- Abandoned packages follow the policy's `abandoned_packages` rule (`proctor/composer-audit/abandoned`), the same value CI's own `composer audit` reads, and PROCTOR passes it to the audit as `--abandoned`. Under `fail` (the bundled policy's value, and the default when a policy does not carry the rule) each abandoned package is a fail; under `report` it is a warning; under `ignore` it is not reported. The message names the replacement when the package's author suggested one (`vendor/pkg is abandoned; use replacement/pkg instead`) and says so when there is none (`vendor/pkg is abandoned with no named replacement`). An abandoned package the app ignores in its own composer.json (`config.policy.abandoned.ignore`, or the legacy `config.audit.ignore-abandoned` while there is no `config.policy.abandoned` block) is a warning, not a fail, the same as an ignored advisory, and a `composer-audit` override whose `package` field matches it downgrades the fail too ([Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html)). Composer exits 1 for an abandoned package under `fail`, so that exit is explained by the list and does not earn the exit-code fail below. A proctor marks the empty desk absent, whatever excuse the note on it gives.
- When the composer executable is not on PATH or the app has no composer.lock, the check fails with a message that starts with `not audited:` (`proctor/composer-audit/skipped`), because a green run that audited nothing is worse than a red one. Install Composer, commit the lock, or record an active `composer-audit` override in policy-overrides.json with a reason and an expiry to downgrade the fail to a warning ([Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html)).

When the audit runs but its stdout is not parseable JSON, the check fails (`proctor/composer-audit/could-not-run`) with a message that starts with `not audited:` and carries the first stderr line, because a green run that audited nothing is worse than a red one. Since Composer 2.10 the audit exits 1 when anything failed it, so a non-zero exit whose JSON lists nothing PROCTOR can read (a malware or policy block, or an error) is itself a fail (`proctor/composer-audit/exit-code`), and only a clean exit with a clean result passes.

A package pattern in `config.policy.advisories.ignore` may use `*`, matched the way composer does, case-insensitively. A warning (`proctor/composer-audit/ignore-entries`) reports the number of ignore entries in composer.json: `config.policy.advisories.ignore-id`, `config.policy.advisories.ignore`, `config.policy.advisories.ignore-severity`, `config.policy.abandoned.ignore`, or the legacy `config.audit.ignore`, `config.audit.ignore-severity` and `config.audit.ignore-abandoned` (the two legacy fallbacks count only while their matching `config.policy` block is absent), so they move to the dated override file ([Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html)). An ignore entry that no active `composer-audit` override in policy-overrides.json backs is a fail of its own (`proctor/composer-audit/unbacked-ignore`), because an ignore without a dated approval never expires: an override backs the entry when its `advisory` field equals the entry's advisory id case-insensitively, its `package` field matches the entry's package the way composer matches patterns, or it carries no scope at all, and an entry that names neither an id nor a package (an ignore-severity level) is backed by an unscoped override only. The proctor checks the invigilator's mark against its own answer sheet, and refuses to sign a green for a red pen.

| Check | Rule id |
| --- | --- |
| `composer-audit` | `proctor/composer-audit` |
| `composer-audit` | `proctor/composer-audit/advisory` |
| `composer-audit` | `proctor/composer-audit/abandoned` |
| `composer-audit` | `proctor/composer-audit/skipped` |
| `composer-audit` | `proctor/composer-audit/could-not-run` |
| `composer-audit` | `proctor/composer-audit/exit-code` |
| `composer-audit` | `proctor/composer-audit/ignore-entries` |
| `composer-audit` | `proctor/composer-audit/unbacked-ignore` |

## composer-version[#](https://proctor.apps.wayne.edu/docs/guides/check-composer.html#composer-version)

`composer-version` fails when the Composer on PATH carries the command-injection holes CVE-2024-35241 and CVE-2024-35242 (2.2.x below 2.2.24, or any other 2.x below 2.7.7) or is Composer 1, which is end of life. It warns when composer cannot be run: `proctor/composer-version/not-found` when there is no composer executable on PATH, and `proctor/composer-version/could-not-determine` when the version could not be determined.

The invigilator checks its own pen: [GHSA-47f6-5gq3-vx9c](https://github.com/advisories/GHSA-47f6-5gq3-vx9c) and [GHSA-v9qv-c7wm-wgmf](https://github.com/advisories/GHSA-v9qv-c7wm-wgmf).

| Check | Rule id |
| --- | --- |
| `composer-version` | `proctor/composer-version` |
| `composer-version` | `proctor/composer-version/not-found` |
| `composer-version` | `proctor/composer-version/could-not-determine` |

## repo-url-credentials[#](https://proctor.apps.wayne.edu/docs/guides/check-composer.html#repo-url-credentials)

`repo-url-credentials` warns when a credential is written into a package source URL:

- A deploy token in the userinfo of a composer.json repository URL or a composer.lock source/dist URL (`https://gitlab+deploy-token-12:***@host/group/lib.git`).
- A bare token user of 20+ characters.
- A `private_token`, `access_token`, `token` or `password` query parameter.
- A `.npmrc` line that sets `_authToken`, `_auth` or `_password` to a literal value (`${SOME_VAR}` is fine).

The secret is masked as `***` in the message, so the report itself is safe to hand back. Warning, not failure. Fix by moving the token to `auth.json`, which is gitignored, or a CI variable, and using one registry URL without credentials. The proctor spots the answer key taped inside the desk before the student does.

| Check | Rule id |
| --- | --- |
| `repo-url-credentials` | `proctor/repo-url-credentials` |

## Related[#](https://proctor.apps.wayne.edu/docs/guides/check-composer.html#related)

- [Checks](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html)
- [Rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html)
- [Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html)
