#Requires -Version 5.1
<#
.SYNOPSIS
    Builds build/proctor.phar, a single-file PHAR of PROCTOR, using Box.

.DESCRIPTION
    The phar lets PREFLIGHT and apps without Composer access run PROCTOR as one
    file. The script:
      1. installs the full dependency set in the project (so vendor/bin/box exists),
      2. copies the runtime sources into a temp dir and installs only the no-dev
         (runtime) dependencies there,
      3. runs Box (pinned in require-dev) against that temp copy, so the phar
         contains src/ and the runtime vendor deps only (no dev deps),
      4. copies the built phar back into build/,
      5. verifies it runs by printing PROCTOR's version.

    Everything runs through Docker, so no PHP or Composer is needed on the host.
    The invigilator packs himself: no phones, no EOL PHP, one file.
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

# The project root is the parent of the scripts/ directory this file lives in.
$Root = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $Root

# Pinned tool images (the same ones the README's Docker sections use).
$ComposerImage = 'composer:2.10.3'
$PhpImage = 'php:8.4-cli'

# Run a docker command with an argument array; fail the script on a non-zero exit.
function Invoke-Docker {
    param([Parameter(Mandatory = $true)][string[]]$DockerArgs)

    & docker @DockerArgs
    if ($LASTEXITCODE -ne 0) {
        throw "docker $($DockerArgs -join ' ') failed with exit code $LASTEXITCODE"
    }
}

# A unique temp dir for the no-dev build copy.
$Tmp = Join-Path $env:TEMP ("proctor-phar-" + [System.Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $Tmp | Out-Null

try {
    Write-Host '==> Installing project dependencies (full, for vendor/bin/box)...'
    Invoke-Docker -DockerArgs @(
        'run', '--rm',
        "-v", "${Root}:/app",
        '-w', '/app',
        $ComposerImage,
        'composer', 'install', '--no-interaction', '--no-progress'
    )

    Write-Host '==> Copying runtime sources into a temp build dir...'
    Copy-Item -Recurse -Force -LiteralPath (Join-Path $Root 'src') -Destination (Join-Path $Tmp 'src')
    Copy-Item -Recurse -Force -LiteralPath (Join-Path $Root 'bin') -Destination (Join-Path $Tmp 'bin')
    Copy-Item -Force -LiteralPath (Join-Path $Root 'composer.json') -Destination (Join-Path $Tmp 'composer.json')
    Copy-Item -Force -LiteralPath (Join-Path $Root 'composer.lock') -Destination (Join-Path $Tmp 'composer.lock')
    Copy-Item -Force -LiteralPath (Join-Path $Root 'box.json.dist') -Destination (Join-Path $Tmp 'box.json.dist')

    Write-Host '==> Installing runtime (no-dev) dependencies in the temp build dir...'
    Invoke-Docker -DockerArgs @(
        'run', '--rm',
        "-v", "${Tmp}:/app",
        '-w', '/app',
        $ComposerImage,
        'composer', 'install', '--no-dev', '--no-interaction', '--no-progress'
    )

    Write-Host '==> Compiling the phar with Box...'
    # Box's Composer compatibility check needs a composer executable, so run it
    # from the composer image (which ships both PHP and composer).
    Invoke-Docker -DockerArgs @(
        'run', '--rm',
        "-v", "${Root}:/app",
        "-v", "${Tmp}:/tmpcopy",
        '-w', '/tmpcopy',
        $ComposerImage,
        'php', '-d', 'phar.readonly=0', '/app/vendor/bin/box', 'compile'
    )

    Write-Host '==> Copying the built phar into build/...'
    New-Item -ItemType Directory -Force -Path (Join-Path $Root 'build') | Out-Null
    Copy-Item -Force -LiteralPath (Join-Path $Tmp 'build/proctor.phar') -Destination (Join-Path $Root 'build/proctor.phar')

    Write-Host '==> Verifying the phar runs...'
    Invoke-Docker -DockerArgs @(
        'run', '--rm',
        "-v", "${Root}:/app",
        '-w', '/app',
        $PhpImage,
        'php', 'build/proctor.phar', '--version'
    )
}
finally {
    Remove-Item -Recurse -Force -LiteralPath $Tmp -ErrorAction SilentlyContinue
}

exit 0
