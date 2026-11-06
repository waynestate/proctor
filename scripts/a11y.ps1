# Site accessibility gate (axe-core in Playwright), run in Docker so no host
# browser is needed. Windows PowerShell 5.1 and PowerShell 7; on Linux and
# macOS use scripts/a11y.sh.
#
# The repo is mounted read-only; only tools/a11y/node_modules lives in a named
# volume, so npm installs once and later runs reuse it.
#
# Every HTML page is checked: site/index.html plus everything under
# site/docs/ and site/handbook/ (when present).
$ErrorActionPreference = "Stop"

Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)

$image = "mcr.microsoft.com/playwright:v1.49.1-noble"

$inner = 'if [ ! -d tools/a11y/node_modules/playwright ]; then if [ -f tools/a11y/package-lock.json ]; then (cd tools/a11y && npm ci --no-audit --no-fund); else (cd tools/a11y && npm install --no-audit --no-fund); fi; fi; set --; for d in docs handbook; do if [ -d "site/$d" ]; then for f in $(find "site/$d" -name "*.html" | sort); do set -- "$@" "--page=${f#site/}"; done; fi; done; set -- "--page=index.html" "$@"; node tools/a11y/check.mjs --root=site "$@"'

docker run --rm `
  -v "${PWD}:/work:ro" `
  -v proctor-a11y-node-modules:/work/tools/a11y/node_modules `
  -w /work `
  $image `
  sh -c $inner

exit $LASTEXITCODE
