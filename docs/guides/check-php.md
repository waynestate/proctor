---
title: PHP checks
description: The checks that grade the PHP floor, the locked Laravel line, patch currency, MySQL 5.6 compatibility and the age of the policy in use.
---

# PHP checks

This page covers the six PROCTOR checks that grade the PHP an app runs on, the Laravel it locks, and the database it talks to: the PHP floor, the Laravel line's state and PHP range, patch currency, MySQL 5.6 compatibility, and the age of the policy in use. Run `vendor/bin/proctor check` against the app path, then fix the finding the report names. The proctor grades the ink before it grades the handwriting.

The other two checks that read PHP and deploy scripts, `portability` and `destructive-deploy`, are covered on the [CI checks](check-ci.md) page.

## php-floor

`php-floor` fails when the lowest PHP version allowed by `require.php` is past security support plus the grace window, when composer.json has no usable php constraint, or when there is no composer.json at all. A missing composer.json is not a tool error: the app gets a verdict, with php-floor failing.

When the floor is usable, the check reduces it to major.minor (`^8.4` becomes `8.4`, `*` becomes `0.0`) and grades it against the policy as of `--today`:

- An active floor passes.
- A floor in security-only support warns, naming the end date and the days left (`ends today`, `1 day left`, or `N days left`).
- A floor past security support plus the grace window fails.

A `require.php` value that is not a valid version constraint is a tool error, not a verdict.

### The runtime, with --php-runtime

A declared `^8.4` proves nothing about the image the app lands on, so when `--php-runtime` names the PHP that will serve the app, `php-floor` adds a second finding for it. `proctor gate` takes the same option, and Squire's release script passes it by default (`SQUIRE_PHP_RUNTIME`).

- A runtime below `require.php` fails, as `proctor/php-floor/runtime-mismatch`: Composer's platform check would stop the app on boot. This is the case the floor alone misses, a supported constraint on an older image.
- A runtime above the constraint's upper bound (9.0 under `^8.4`) warns under the same rule: it boots, but the app was never tested there.
- A runtime inside the constraint is graded on the policy's table as `proctor/php-floor/runtime`: active passes, security-only warns with the end date and the days left, and end of life warns. It only warns because a runtime inside the constraint can only be end of life when the floor is too, and the floor already fails, so a baselined gate does not grow a second blocker for the same cause. A line the policy does not list warns that its support cannot be graded.

```text
PASS php-floor: php floor 8.4 is active
FAIL php-floor: php runtime 8.2.29 is below require.php "^8.4": Composer's platform check stops the app on boot; serve it on a PHP the constraint allows
```

An active `php-floor` override covers both findings, the same as any other fail of the check.

| Check | Rule id |
| --- | --- |
| `php-floor` | `proctor/php-floor` |
| `php-floor` | `proctor/php-floor/runtime` |
| `php-floor` | `proctor/php-floor/runtime-mismatch` |

## unpatched

`unpatched` warns when a locked component is behind the policy's `latest_patch` for its version line, and passes when it is current. It reads laravel from composer.lock (`laravel/framework`), vue and inertia from package-lock.json (`vue`, `@inertiajs/vue3`), and the PHP runtime from `--php-runtime`. There is no finding when the component, its version line, or `latest_patch` is absent from the policy.

CI should pass the deploy image's PHP, not the runner's.

| Check | Rule id |
| --- | --- |
| `unpatched` | `proctor/unpatched` |

## laravel-eol

`laravel-eol` classifies the locked `laravel/framework` version against the policy as of `--today`. It reads the version from composer.lock (`packages`, then `packages-dev`); a leading `v` is stripped, and a version that is not plain numeric (a dev alias) earns no finding, because the reader does not guess.

- An active line passes, naming the line's end date when it has one.
- A line in security-only support warns, naming the end date and the days left (`ends today`, `1 day left`, or `N days left`), the same midnight-to-midnight comparison php-floor uses.
- A line past its security end date fails as `eol`; past the end date plus the policy's grace window it fails as `eol_grace_expired`. Both name the security end date when the line has one.

An active `laravel-eol` override downgrades the `eol` fail to a warning; it never downgrades `eol_grace_expired`. There is no finding when composer.lock has no `laravel/framework` or the version resolves to no policy line.

| Check | Rule id |
| --- | --- |
| `laravel-eol` | `proctor/laravel-eol` |

## laravel-php

`laravel-php` checks composer.json's `require.php` against the PHP range the policy records for the locked Laravel major. It reads the version from composer.lock and the range from the policy's `requires` block for that major's line, then asks two questions of the constraint: does it allow any PHP version inside the range, and does it allow any PHP below the range's floor? Composer and the deployed runtime decide the upper end, so an open upper bound such as `^8.2` passes; the range's max counts as supported, so a max of `8.5` admits everything below `8.6`.

- A constraint that allows no PHP version inside the range fails: `php constraint "X" allows no PHP in the MIN to MAX range of laravel L`. The app would not boot on any PHP the locked major runs on.
- A constraint whose lowest allowed version is below the range's floor fails: `php constraint "X" allows PHP below MIN, the floor of laravel L (MIN to MAX)`. The app claims a PHP the locked major cannot run on.
- Every other constraint passes: `php constraint "X" fits the MIN to MAX range of laravel L`, including Laravel's own skeleton constraint `^8.2` on a line floored at 8.2.

An active `laravel-php` override downgrades the fail to a warning. There is no finding when composer.lock has no `laravel/framework`, the line has no recorded range, or composer.json declares no `require.php`: php-floor owns the missing-constraint verdict. A present `require.php` that is not a valid version constraint is a tool error.

| Check | Rule id |
| --- | --- |
| `laravel-php` | `proctor/laravel-php` |

## mysql56

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

## policy-age

`policy-age` warns when the policy in use is more than 60 days old (its `refreshed_on` date is more than 60 days before `--today`), or when a non-bundled policy has no `refreshed_on` date. Warning only: an old policy never fails a run, and the bundled policy may omit the date. It exists so a stopped CREED refresh robot is visible instead of silent.

| Check | Rule id |
| --- | --- |
| `policy-age` | `proctor/policy-age` |

## Related

- [Checks](checks-overview.md)
- [CI checks](check-ci.md)
- [Policy](policy.md)
- [Rule ids](../reference/rule-ids.md)
