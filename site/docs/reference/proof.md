---
title: "Proof | PROCTOR docs"
description: "Where to see PROCTOR's proof runs against real Wayne State applications, behind a Wayne State employee sign-in."
url: https://proctor.apps.wayne.edu/docs/reference/proof.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Proof[#](https://proctor.apps.wayne.edu/docs/reference/proof.html#proof)

This page points at PROCTOR's proof runs against real Wayne State applications, and how the docs site and the test suite are built and run. To read a report once you have one, start with [Formats](https://proctor.apps.wayne.edu/docs/guides/formats.html).

## Proof runs[#](https://proctor.apps.wayne.edu/docs/reference/proof.html#proof-runs)

Proof runs against real Wayne State applications are on a private site, [proof.apps.wayne.edu/proctor](https://proof.apps.wayne.edu/proctor/), behind a Wayne State employee sign-in.

## Docs[#](https://proctor.apps.wayne.edu/docs/reference/proof.html#docs)

The documentation lives at [proctor.apps.wayne.edu/docs](https://proctor.apps.wayne.edu/docs/) and is built from `docs/` by Squire's docs builder:

```
composer docs
```

renders the pages into `site/docs/` and fills the generated command and environment reference from the CLI and the source. The pages are committed, so the site stays static. Check without writing:

```
composer docs:check
```

which runs as part of `composer ci`.

## Running the tests in Docker[#](https://proctor.apps.wayne.edu/docs/reference/proof.html#running-the-tests-in-docker)

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](https://nougat.snu.gs/enterprise-apps/proctor/-/blob/main/MAINTAINING.md).

No PHP is needed on the host:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
```

The same works with `php:8.3-cli` in place of `php:8.4-cli`.

Strongly recommended: a git.wayne.edu instance runner.

## Related[#](https://proctor.apps.wayne.edu/docs/reference/proof.html#related)

- [Install](https://proctor.apps.wayne.edu/docs/install.html)
- [Commands](https://proctor.apps.wayne.edu/docs/reference/commands.html)
- [GitHub code scanning](https://proctor.apps.wayne.edu/docs/guides/github-code-scanning.html)
