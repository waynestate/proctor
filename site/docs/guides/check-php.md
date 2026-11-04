---
title: "PHP checks | PROCTOR docs"
description: "The checks that grade the PHP floor, patch currency, platform portability, MySQL 5.6 compatibility and deploy scripts."
url: https://proctor.apps.wayne.edu/docs/guides/check-php.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# PHP checks[#](https://proctor.apps.wayne.edu/docs/guides/check-php.html#php-checks)

This page covers the four PROCTOR checks that grade the PHP an app runs on and the database it talks to: the PHP floor, patch currency, MySQL 5.6 compatibility, and the age of the policy in use. Run `vendor/bin/proctor check` against the app path, then fix the finding the report names. The proctor grades the ink before it grades the handwriting.

The other two checks that read PHP and deploy scripts, `portability` and `destructive-deploy`, are covered on the [CI checks](https://proctor.apps.wayne.edu/docs/guides/check-ci.html) page.

## php-floor[#](https://proctor.apps.wayne.edu/docs/guides/check-php.html#php-floor)

`php-floor` fails when the lowest PHP version allowed by `require.php` is past security support plus the grace window, when composer.json has no usable php constraint, or when there is no composer.json at all. A missing composer.json is not a tool error: the app gets a verdict, with php-floor failing.

When the floor is usable, the check reduces it to major.minor (`^8.4` becomes `8.4`, `*` becomes `0.0`) and grades it against the policy as of `--today`:

- An active floor passes.
- A floor in security-only support warns, naming the end date and the days left (`ends today`, `1 day left`, or `N days left`).
- A floor past security support plus the grace window fails.

A `require.php` value that is not a valid version constraint is a tool error, not a verdict.

| Check | Rule id |
| --- | --- |
| `php-floor` | `proctor/php-floor` |

## unpatched[#](https://proctor.apps.wayne.edu/docs/guides/check-php.html#unpatched)

`unpatched` warns when a locked component is behind the policy's `latest_patch` for its version line, and passes when it is current. It reads laravel from composer.lock (`laravel/framework`), vue and inertia from package-lock.json (`vue`, `@inertiajs/vue3`), and the PHP runtime from `--php-runtime`. There is no finding when the component, its version line, or `latest_patch` is absent from the policy.

CI should pass the deploy image's PHP, not the runner's.

| Check | Rule id |
| --- | --- |
| `unpatched` | `proctor/unpatched` |

## mysql56[#](https://proctor.apps.wayne.edu/docs/guides/check-php.html#mysql56)

`mysql56` warns when migrations or app code use MySQL features the shared 5.6 server rejects. Citadel's shared MySQL server is 5.6, so migrations must avoid newer features. Warnings, not failures: an app on its own newer database is not affected.

When the app has no `database/migrations` directory the check passes: `no Laravel migrations found; skipped`. Scanned: every `*.php` file under `database/migrations/`, `app/` and `routes/`, plus `config/database.php`, which is scanned for the upsert-alias rule only. Skipped at any depth: `vendor/`, `node_modules/`, `storage/`, `tests/` and `.git/`.

Each feature that needs a newer MySQL is its own warning:

- JSON columns (in `database/`) and JSON queries (`proctor/mysql56/json`).
- Generated columns (`proctor/mysql56/generated`).
- `renameColumn`/`renameIndex` (`proctor/mysql56/rename`).
- Vector, invisible and spatial columns (`proctor/mysql56/newer-column`).
- `Schema::hasColumn`-style introspection (`proctor/mysql56/introspection`).
- Raw SQL with 5.7+ syntax (`proctor/mysql56/raw-sql`).
- The upsert row alias in `config/database.php` (`proctor/mysql56/upsert-alias`).
- Indexed strings over 191 characters (`proctor/mysql56/string-length`).
- Date columns defaulting to the current time (`proctor/mysql56/current-date`).
- The `db:show`/`db:monitor` commands (`proctor/mysql56/command`).

Two more rules watch the file level: `app/Providers/AppServiceProvider.php` not calling `Schema::defaultStringLength(191)` (`proctor/mysql56/string-default`), and a scanned file that could not be parsed (`proctor/mysql56/parse-error`).

Fix by avoiding the feature (a raw `ALTER TABLE ... CHANGE` instead of `renameColumn`, `timestamp` instead of a date column defaulting to now, `Schema::defaultStringLength(191)` in `boot()`) or by moving the app to its own database. HTTP `->json()` calls are not flagged, and a long string only warns when the same migration indexes it.

| Check | Rule id |
| --- | --- |
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

## policy-age[#](https://proctor.apps.wayne.edu/docs/guides/check-php.html#policy-age)

`policy-age` warns when the policy in use is more than 60 days old (its `refreshed_on` date is more than 60 days before `--today`), or when a non-bundled policy has no `refreshed_on` date. Warning only: an old policy never fails a run, and the bundled policy may omit the date. It exists so a stopped CREED refresh robot is visible instead of silent.

| Check | Rule id |
| --- | --- |
| `policy-age` | `proctor/policy-age` |

## Related[#](https://proctor.apps.wayne.edu/docs/guides/check-php.html#related)

- [Checks](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html)
- [CI checks](https://proctor.apps.wayne.edu/docs/guides/check-ci.html)
- [Policy](https://proctor.apps.wayne.edu/docs/guides/policy.html)
- [Rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html)
