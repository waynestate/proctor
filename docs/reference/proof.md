---
title: Proof
description: Where to see PROCTOR's proof runs against real Wayne State applications, behind a Wayne State employee sign-in.
---

# Proof

This page points at PROCTOR's proof runs against real Wayne State applications, and how the docs site and the test suite are built and run. To read a report once you have one, start with [Formats](../guides/formats.md).

## Last run

| Check | Date | Commit | Result | Duration |
| --- | --- | --- | --- | --- |
| `composer ci` in Docker (composer:2.10.3) | 2026-11-07 | 4201f99 | pass: 334 tests, 4682 assertions; lint 93 files; static analysis clean; agent-ready 25 files; docs 20 pages; 0 advisories | 8 min 12 s |

To refresh this table, rerun the checks described on this page on the current commit and replace the rows with the new output. Not run here: the proof runs against real Wayne State applications, which live on a private site behind an employee sign-in.

## Proof runs

Proof runs against real Wayne State applications are on a private site, [proof.apps.wayne.edu/proctor](https://proof.apps.wayne.edu/proctor/), behind a Wayne State employee sign-in.

## Docs

The documentation lives at [proctor.apps.wayne.edu/docs](https://proctor.apps.wayne.edu/docs/) and is built from `docs/` by Squire's docs builder:

```
composer docs
```

renders the pages into `site/docs/` and fills the generated command and environment reference from the CLI and the source. The pages are committed, so the site stays static. Check without writing:

```
composer docs:check
```

which runs as part of `composer ci`.

## Running the tests in Docker

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](https://github.com/waynestate/proctor/blob/main/MAINTAINING.md).

No PHP is needed on the host:

```
docker run --rm -v "${PWD}:/app" -v stack-composer-cache:/tmp/cache -w /app -e COMPOSER_PROCESS_TIMEOUT=0 composer:2.10.3 sh -c "composer install -q --no-progress && composer ci"
```

The `stack-composer-cache` volume keeps Composer's downloads between runs; Docker creates it the first time.

The plain php images lack git and composer, so 22 tests fail there; the composer image above carries both tools.

Strongly recommended: a git.wayne.edu instance runner.

## Related

- [Install](../install.md)
- [Commands](commands.md)
- [GitHub code scanning](../guides/github-code-scanning.md)
