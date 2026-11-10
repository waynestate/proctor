---
title: "Install | PROCTOR docs"
description: "Install PROCTOR as a Composer dev dependency so the command is available as vendor/bin/proctor."
url: https://proctor.apps.wayne.edu/docs/install.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Install [#](https://proctor.apps.wayne.edu/docs/install.html#install)

## Requirements [#](https://proctor.apps.wayne.edu/docs/install.html#requirements)

- PHP `^8.3`. composer.json pins the platform to `8.3.0`, so the package resolves against 8.3 and runs on anything newer.
- No PHP extensions. composer.json declares no `ext-*` requirement; the runtime libraries (`symfony/console`, `symfony/yaml`, `composer/semver`, `nikic/php-parser`) arrive as Composer packages.
- Composer, for the main install path below. The single-file PHAR route needs only a PHP CLI and a download from the GitLab Release.
- GitLab 18.7 or later for the stack's CI (the stack's CI components use `spec:component`). See BEST_PRACTICES 8.7.

Where the code lives: the canonical repository is on git.wayne.edu, [enterprise-apps/proctor](https://git.wayne.edu/enterprise-apps/proctor). GitHub ([waynestate/proctor](https://github.com/waynestate/proctor)) is a read-only mirror of it. Open issues and merge requests on git.wayne.edu, not on the mirror. Your proctor keeps one answer sheet, and this is it; the mirrors hold photocopies.

## Single-file PHAR [#](https://proctor.apps.wayne.edu/docs/install.html#single-file-phar)

The single-file PHAR runs PROCTOR as one file, for a deploy host or an app without Composer access. (Preflight's `proctor` component does not use it: it installs the app with Composer and runs the app's own `vendor/bin/proctor`.) Since 0.6.0, every tag runs the `release-phar` job in `.gitlab-ci.yml`, which attaches `proctor.phar` and its `proctor.phar.sha256` checksum file to that tag's GitLab Release; the maintainer tags, and no tag means no release. The `composer require` install below stays the main method; the phar is the one-file alternative.

Download both files for the tag you want, verify the checksum, then run it. Replace `<release>` with the base URL of the release's asset links (the release page lists both files).

Linux / macOS (sh), one line:

```sh
curl -fsSL -o proctor.phar "<release>/proctor.phar" -o proctor.phar.sha256 "<release>/proctor.phar.sha256" && sha256sum -c proctor.phar.sha256 && php proctor.phar --version
```

Windows (PowerShell), one line, no bash needed:

```powershell
Invoke-WebRequest "<release>/proctor.phar" -OutFile proctor.phar; Invoke-WebRequest "<release>/proctor.phar.sha256" -OutFile proctor.phar.sha256; if ((Get-FileHash proctor.phar -Algorithm SHA256).Hash -ne (Get-Content proctor.phar.sha256 -TotalCount 1).Trim().Split(' ')[0]) { throw "sha256 mismatch" }; php proctor.phar --version
```

For a commit between tags, or a release whose job did not attach the files, build it from a checkout with the script for your OS (both need Docker, and no PHP or Composer on the host): `.\scripts\build-phar.ps1` on Windows, `scripts/build-phar.sh` on Linux / macOS. Each script writes `build/proctor.phar` and verifies it by printing PROCTOR's version.

## Install [#](https://proctor.apps.wayne.edu/docs/install.html#install-1)

```
composer require waynestate/proctor --dev
```

The command is then `vendor/bin/proctor`. Add CREED next to it, so PROCTOR marks against the newest published policy instead of the copy it bundles:

```
composer require waynestate/creed --dev
```

Already in a [Sourdough](https://sourdough.apps.wayne.edu/) app or [WELD](https://weld.apps.wayne.edu/)? Both are there, and `composer proctor` runs the check.

## Verify [#](https://proctor.apps.wayne.edu/docs/install.html#verify)

Run the check against the app:

```
vendor/bin/proctor check
```

Expected: the text report. A header line with the tool and the versions of the external tools used (composer), a line naming the policy in use (origin, path, refresh date), one line per finding, one line per override, then a RESULT line. Exit codes: 0 for pass (warnings allowed), 1 for one or more failures, 2 for a tool error. A proctor that prints a mark sheet is a proctor that is on duty.

## Next steps [#](https://proctor.apps.wayne.edu/docs/install.html#next-steps)

- [Quick start](https://proctor.apps.wayne.edu/docs/guides/quick-start.html): run the first check and read the report.
- [Reference: commands](https://proctor.apps.wayne.edu/docs/reference/commands.html): every command and option, with the [rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html) and [exit codes](https://proctor.apps.wayne.edu/docs/reference/exit-codes.html) alongside.
