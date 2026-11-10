---
title: "Rule ids | PROCTOR docs"
description: "The stable, namespaced rule id that every finding carries, grouped by check."
url: https://proctor.apps.wayne.edu/docs/reference/rule-ids.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Rule ids [#](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html#rule-ids)

Every finding carries a stable, namespaced rule id: `proctor/<check-id>` for a check's default finding, `proctor/<check-id>/<rule>` for a named rule. The id is the finding's identity in the machine formats: `rule_id` in `json`, `ruleId` in `sarif`, and `check_name` in `codequality`. A rule keeps the same id across runs and across formats even when its message changes. The table below is the full set, grouped by check.

## Rule ids by check [#](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html#rule-ids-by-check)

| Check | Rule id |
| --- | --- |
| `php-floor` | `proctor/php-floor` |
| `unpatched` | `proctor/unpatched` |
| `laravel-eol` | `proctor/laravel-eol` |
| `laravel-php` | `proctor/laravel-php` |
| `node-eol` | `proctor/node-eol` |
| `node-eol` | `proctor/node-eol/engines` |
| `node-eol` | `proctor/node-eol/odd-major` |
| `lock-committed` | `proctor/lock-committed` |
| `lock-committed` | `proctor/lock-committed/out-of-sync` |
| `lock-tracked` | `proctor/lock-tracked` |
| `destructive-deploy` | `proctor/destructive-deploy` |
| `crlf-deploy-script` | `proctor/crlf-deploy-script` |
| `crlf-deploy-script` | `proctor/crlf-deploy-script/no-gitattributes` |
| `gitattributes-eol` | `proctor/gitattributes-eol` |
| `gitattributes-eol` | `proctor/gitattributes-eol/not-pinned` |
| `gitattributes-eol` | `proctor/gitattributes-eol/crlf` |
| `entrypoint-migrate` | `proctor/entrypoint-migrate` |
| `entrypoint-migrate` | `proctor/entrypoint-migrate/runs` |
| `case-clash` | `proctor/case-clash` |
| `overrides` | `proctor/overrides` |
| `overrides` | `proctor/overrides/expiring` |
| `overrides` | `proctor/overrides/too-long` |
| `overrides` | `proctor/overrides/unused` |
| `override-approver` | `proctor/override-approver` |
| `override-approver` | `proctor/override-approver/self-approved` |
| `override-approver` | `proctor/override-approver/could-not-run` |
| `unbounded-constraints` | `proctor/unbounded-constraints` |
| `unbounded-constraints` | `proctor/unbounded-constraints/unbounded` |
| `unbounded-constraints` | `proctor/unbounded-constraints/repository` |
| `unbounded-constraints` | `proctor/unbounded-constraints/minimum-stability` |
| `unbounded-constraints` | `proctor/unbounded-constraints/insecure-url` |
| `dev-only-packages` | `proctor/dev-only-packages` |
| `dev-only-packages` | `proctor/dev-only-packages/exposes-internals` |
| `dev-only-packages` | `proctor/dev-only-packages/test-tool` |
| `composer-config` | `proctor/composer-config` |
| `composer-config` | `proctor/composer-config/allow-plugins-all` |
| `composer-config` | `proctor/composer-config/secure-http-off` |
| `composer-config` | `proctor/composer-config/lock-off` |
| `composer-config` | `proctor/composer-config/audit-blocking-off` |
| `composer-config` | `proctor/composer-config/policy-off` |
| `composer-config` | `proctor/composer-config/policy-malware-off` |
| `composer-config` | `proctor/composer-config/policy-advisories-off` |
| `composer-config` | `proctor/composer-config/platform-php-unset` |
| `composer-cooldown` | `proctor/composer-cooldown` |
| `composer-cooldown` | `proctor/composer-cooldown/missing` |
| `composer-cooldown` | `proctor/composer-cooldown/not-blocking` |
| `composer-cooldown` | `proctor/composer-cooldown/too-short` |
| `portability` | `proctor/portability` |
| `portability` | `proctor/portability/host-path` |
| `portability` | `proctor/portability/proxy-address` |
| `portability` | `proctor/portability/local-session` |
| `portability` | `proctor/portability/htaccess-php-setting` |
| `portability` | `proctor/portability/parse-error` |
| `portability` | `proctor/portability/dynamic-call` |
| `mysql56` | `proctor/mysql56` |
| `mysql56` | `proctor/mysql56/json` |
| `mysql56` | `proctor/mysql56/generated` |
| `mysql56` | `proctor/mysql56/rename` |
| `mysql56` | `proctor/mysql56/newer-column` |
| `mysql56` | `proctor/mysql56/introspection` |
| `mysql56` | `proctor/mysql56/raw-sql` |
| `mysql56` | `proctor/mysql56/string-length` |
| `mysql56` | `proctor/mysql56/current-date` |
| `mysql56` | `proctor/mysql56/command` |
| `mysql56` | `proctor/mysql56/upsert-alias` |
| `mysql56` | `proctor/mysql56/string-default` |
| `mysql56` | `proctor/mysql56/parse-error` |
| `mysql-eol` | `proctor/mysql-eol` |
| `mysql-minimum` | `proctor/mysql-minimum` |
| `mysql-minimum` | `proctor/mysql-minimum/below` |
| `ci-pipelines` | `proctor/ci-pipelines` |
| `ci-pipelines` | `proctor/ci-pipelines/gitlab-missing` |
| `ci-pipelines` | `proctor/ci-pipelines/gitlab-parse` |
| `ci-pipelines` | `proctor/ci-pipelines/gitlab-no-ci` |
| `ci-pipelines` | `proctor/ci-pipelines/github-missing` |
| `ci-pipelines` | `proctor/ci-pipelines/github-parse` |
| `ci-pipelines` | `proctor/ci-pipelines/github-no-ci` |
| `ci-pipelines` | `proctor/ci-pipelines/github-private` |
| `ci-pipelines` | `proctor/ci-pipelines/unpinned-uses` |
| `ci-pipelines` | `proctor/ci-pipelines/composer-audit-off` |
| `ci-pipelines` | `proctor/ci-pipelines/composer-update` |
| `ci-pipelines` | `proctor/ci-pipelines/allow-overrides` |
| `preflight-pinned` | `proctor/preflight-pinned` |
| `preflight-pinned` | `proctor/preflight-pinned/unpinned` |
| `preflight-pinned` | `proctor/preflight-pinned/script-override` |
| `preflight-gates` | `proctor/preflight-gates` |
| `preflight-gates` | `proctor/preflight-gates/no-include` |
| `preflight-gates` | `proctor/preflight-gates/allow-failure` |
| `preflight-gates` | `proctor/preflight-gates/no-proctor-job` |
| `composer-audit` | `proctor/composer-audit` |
| `composer-audit` | `proctor/composer-audit/advisory` |
| `composer-audit` | `proctor/composer-audit/abandoned` |
| `composer-audit` | `proctor/composer-audit/skipped` |
| `composer-audit` | `proctor/composer-audit/could-not-run` |
| `composer-audit` | `proctor/composer-audit/exit-code` |
| `composer-audit` | `proctor/composer-audit/ignore-entries` |
| `composer-audit` | `proctor/composer-audit/unbacked-ignore` |
| `composer-version` | `proctor/composer-version` |
| `composer-version` | `proctor/composer-version/not-found` |
| `composer-version` | `proctor/composer-version/could-not-determine` |
| `repo-url-credentials` | `proctor/repo-url-credentials` |
| `instant-client-committed` | `proctor/instant-client-committed` |
| `env-outside-config` | `proctor/env-outside-config` |
| `env-outside-config` | `proctor/env-outside-config/suppressed` |
| `raw-identity-header` | `proctor/raw-identity-header` |
| `app-key` | `proctor/app-key` |
| `app-key` | `proctor/app-key/committed-env` |
| `app-key` | `proctor/app-key/committed-key` |
| `app-key` | `proctor/app-key/cookie-sessions` |
| `app-debug` | `proctor/app-debug` |
| `app-debug` | `proctor/app-debug/enabled` |
| `display-errors` | `proctor/display-errors` |
| `display-errors` | `proctor/display-errors/on` |
| `display-errors` | `proctor/display-errors/log-off` |
| `directory-listing` | `proctor/directory-listing` |
| `directory-listing` | `proctor/directory-listing/indexes` |
| `csp-unsafe-inline` | `proctor/csp-unsafe-inline` |
| `csp-unsafe-inline` | `proctor/csp-unsafe-inline/script-src` |
| `robots-txt` | `proctor/robots-txt` |
| `robots-txt` | `proctor/robots-txt/missing` |
| `robots-txt` | `proctor/robots-txt/allows-all` |
| `vite-secrets` | `proctor/vite-secrets` |
| `vite-secrets` | `proctor/vite-secrets/env-file` |
| `vite-secrets` | `proctor/vite-secrets/source` |
| `served-folder` | `proctor/served-folder` |
| `served-folder` | `proctor/served-folder/debug-file` |
| `served-folder` | `proctor/served-folder/phpinfo` |
| `world-writable` | `proctor/world-writable` |
| `wayneui-forked-views` | `proctor/wayneui-forked-views` |
| `wayneui-outdated` | `proctor/wayneui-outdated` |
| `wayneui-outdated` | `proctor/wayneui-outdated/skipped` |
| `policy-age` | `proctor/policy-age` |

`proctor/composer-audit/skipped` is a failure, not an info finding: it fires when the composer executable is not on PATH or the app has no composer.lock, its message starts with `not audited:`, and an active `composer-audit` override downgrades it to a warning.

`proctor/ci-pipelines/allow-overrides` warns only when the pipeline environment sets `PROCTOR_ALLOW_OVERRIDES` to `1`: the variable lets the app pass `--policy` and `--today` in CI (BEST_PRACTICES 1.2), so it could grade itself against a policy or a date it picks. Any other value leaves the guard on and gives no finding. Fix: remove the variable; only PROCTOR's own pipeline needs it.

## Kept in step [#](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html#kept-in-step)

`tests/RuleIdContractTest.php` keeps this table in step with the checks: a rule id that is emitted but not listed in the known set fails the suite, and the test parses the README's copy of this table, so a known rule id missing from it, or a row that names an unknown rule id, fails the suite too. This page's table is held to the README's, row for row, by the same test file. The proctor stamps each id in pencil so small only the head office can read it, and it never smudges.

## Related [#](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html#related)

- [Commands](https://proctor.apps.wayne.edu/docs/reference/commands.html)
- [Exit codes](https://proctor.apps.wayne.edu/docs/reference/exit-codes.html)
- [Formats](https://proctor.apps.wayne.edu/docs/guides/formats.html)
