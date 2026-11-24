# PROCTOR open questions

These are the questions PROCTOR cannot settle in its own code, each with the owner who can answer it and the default PROCTOR ships until they do.

## Known open at 1.0

None of these gates 1.0. Each row names what the answer would unlock and what runs today. The numbers are the practices in [BEST_PRACTICES.md](BEST_PRACTICES.md).

| # | Question | Who can answer | What it blocks | Default built |
|---|---|---|---|---|
| 1.2 | Where should `PROCTOR_ALLOW_OVERRIDES` live: a protected or group-level CI variable that the app's own pipeline cannot set? | The GitLab group owners on git.wayne.edu | Moving the override switch out of reach of the checked app, so `--policy` and `--today` can only be allowed by a trusted job | In CI `--policy` and `--today` are refused unless `PROCTOR_ALLOW_OVERRIDES` is exactly `1` (`src/Policy/CiGuard.php`); `ci-pipelines` warns when an app's own pipeline sets it, and `preflight-gates` warns when a gate is skipped, made optional or allowed to fail. The vendored policy edits and image or variables overrides in `PreflightGatesCheck` are still open on PROCTOR's side. |
| 8.1 | Should Preflight's `proctor` component run the released PHAR instead of installing the app and running its `vendor/bin/proctor`? | The Preflight maintainers (the change lives in the Preflight repo, `templates/proctor/template.yml`) | Checking an app without installing its dependencies at all | Every tag builds, verifies and publishes `build/proctor.phar` with its checksum (`release-phar` job). Preflight's component installs the app with `--no-plugins --no-scripts --ignore-platform-reqs`, so the app's plugins and scripts do not run in that job and an app on an old PHP still installs. |
| 5.7 | Can an override's approval be enforced by the platform, through Code Owner approval on the overrides file? | The git.wayne.edu instance administrators (GitLab Community Edition has no Code Owner approval) | Proving that the named approver really approved | The `override-approver` check reads git blame and reports `proctor/override-approver/self-approved` when the approver wrote the entry, or `could-not-run` with no history; CREED's `rules.override_self_approval` makes it a warning (the default) or a failure. The merge request approval stays the human control. |
| 8.5 | Who holds the signing key, and where is release provenance published? | The stack's release owners | Signed releases and provenance that says where each one was built | Releases are tagged (0.1.0 through 1.0.0), and reports read the installed `waynestate/proctor` version from Composer, with the in-code constant as the fallback. No release is signed yet. |
| 7.2 | Can the deploy gate reach advisory data offline, for example from a local advisory mirror? | The owners of the deploy hosts and their network | An offline `proctor gate` that can still fail on a known advisory | `composer-audit` needs the network; an offline gate warns that advisory data is missing and never fails on it, and `--timeout` caps the audit at 60 seconds in the gate. |
| 8.7 | Is each GitLab instance and runner that runs the gates on a patched release? | The GitLab instance administrators | Trusting a gate result from that server | Documented only: the install page lists GitLab 18.7 as the floor (Preflight's `spec:component`), and BEST_PRACTICES.md names the patch notes. PROCTOR cannot see the server version. |

## Missing configuration

Nothing. PROCTOR needs no secret, token or account to run: `proctor check` and `proctor gate` read the app, the policy and the clock, and the optional network calls (`composer audit`, the update notice) need no credentials.
