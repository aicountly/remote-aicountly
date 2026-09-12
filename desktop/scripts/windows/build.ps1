<#
.SYNOPSIS
    Build AICOUNTLY Remote for Windows: the service, the application and the
    installers.

.DESCRIPTION
    One script for a developer's machine and for CI, so the two cannot drift.
    It does four things in order:

      1. builds `AicountlyRemoteService.exe`;
      2. copies it into `src-tauri/binaries/` under the target-triple name
         Tauri expects for a sidecar;
      3. builds the front end and the application, producing the NSIS and MSI
         installers;
      4. reports exactly what it produced and whether any of it is signed.

    It does NOT sign anything. Signing is `sign.ps1`, it needs credentials this
    script never sees, and keeping them apart is what makes it safe to run this
    on any machine.

.PARAMETER Configuration
    `release` (default) or `debug`.

.PARAMETER Target
    The Rust target triple. Defaults to the host's.

.PARAMETER SkipFrontend
    Skip `npm ci`. For a rebuild where node_modules is already correct.

.EXAMPLE
    pwsh -File scripts/windows/build.ps1
#>

[CmdletBinding()]
param(
    [ValidateSet('release', 'debug')]
    [string] $Configuration = 'release',

    [string] $Target = 'x86_64-pc-windows-msvc',

    [switch] $SkipFrontend
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$desktopRoot = (Resolve-Path (Join-Path $PSScriptRoot '..' '..')).Path
Push-Location $desktopRoot

try {
    Write-Host "AICOUNTLY Remote — Windows build" -ForegroundColor Cyan
    Write-Host "  root:          $desktopRoot"
    Write-Host "  configuration: $Configuration"
    Write-Host "  target:        $Target"
    Write-Host ''

    # ----------------------------------------------------------------- version
    #
    # One authoritative version, in `desktop/Cargo.toml`'s `workspace.package`.
    # Everything else derives from it, and this reads it back rather than
    # taking a parameter — a build that could be told a different version is a
    # build that can produce an installer disagreeing with the binary inside it.
    $cargoToml = Get-Content (Join-Path $desktopRoot 'Cargo.toml') -Raw
    if ($cargoToml -notmatch '(?m)^version\s*=\s*"([^"]+)"') {
        throw 'The workspace version could not be read from desktop/Cargo.toml.'
    }
    $version = $Matches[1]
    Write-Host "  version:       $version" -ForegroundColor Green

    $tauriConf = Get-Content (Join-Path $desktopRoot 'src-tauri/tauri.conf.json') -Raw | ConvertFrom-Json
    if ($tauriConf.version -ne $version) {
        throw "src-tauri/tauri.conf.json says $($tauriConf.version) but the workspace says $version. They must agree."
    }

    # ------------------------------------------------------------- the service
    Write-Host ''
    Write-Host 'Building the Windows service...' -ForegroundColor Cyan

    $cargoArgs = @('build', '-p', 'aicountly-remote-service', '--target', $Target)
    if ($Configuration -eq 'release') { $cargoArgs += '--release' }

    & cargo @cargoArgs
    if ($LASTEXITCODE -ne 0) { throw 'The service did not build.' }

    $serviceExe = Join-Path $desktopRoot "target/$Target/$Configuration/AicountlyRemoteService.exe"
    if (-not (Test-Path $serviceExe)) { throw "The service binary is missing: $serviceExe" }

    # Tauri finds a sidecar by `<name>-<target triple>.exe` and installs it
    # beside the application with the triple stripped.
    $binaries = Join-Path $desktopRoot 'src-tauri/binaries'
    New-Item -ItemType Directory -Force -Path $binaries | Out-Null
    Copy-Item $serviceExe (Join-Path $binaries "AicountlyRemoteService-$Target.exe") -Force

    # ------------------------------------------------------------ the frontend
    if (-not $SkipFrontend) {
        Write-Host ''
        Write-Host 'Installing front-end dependencies...' -ForegroundColor Cyan
        & npm ci
        if ($LASTEXITCODE -ne 0) { throw 'npm ci failed.' }
    }

    # ---------------------------------------------------------- the application
    Write-Host ''
    Write-Host 'Building the application and installers...' -ForegroundColor Cyan

    $tauriArgs = @('run', 'tauri', 'build', '--target', $Target)
    if ($Configuration -eq 'debug') { $tauriArgs += '--debug' }

    & npm @tauriArgs
    if ($LASTEXITCODE -ne 0) { throw 'The application did not build.' }

    # ----------------------------------------------------------------- the report
    $bundle = Join-Path $desktopRoot "target/$Target/$Configuration/bundle"
    $artifacts = @()
    foreach ($pattern in @('nsis/*.exe', 'msi/*.msi')) {
        $artifacts += Get-ChildItem (Join-Path $bundle $pattern) -ErrorAction SilentlyContinue
    }

    Write-Host ''
    Write-Host 'Produced:' -ForegroundColor Cyan

    if ($artifacts.Count -eq 0) {
        Write-Warning 'No installer was produced. Check the Tauri output above.'
    }

    foreach ($artifact in $artifacts) {
        $signature = Get-AuthenticodeSignature $artifact.FullName
        $status = if ($signature.Status -eq 'Valid') { 'signed' } else { 'UNSIGNED' }
        $colour = if ($signature.Status -eq 'Valid') { 'Green' } else { 'Yellow' }

        Write-Host ("  {0,-9} {1}" -f $status, $artifact.FullName) -ForegroundColor $colour
    }

    # An unsigned artifact is a development build and must never be described
    # as anything else. Saying so here means nobody has to remember.
    if ($artifacts | Where-Object { (Get-AuthenticodeSignature $_.FullName).Status -ne 'Valid' }) {
        Write-Host ''
        Write-Host 'UNSIGNED DEVELOPMENT BUILD — not for distribution.' -ForegroundColor Yellow
        Write-Host 'Windows SmartScreen will warn about these, which is correct behaviour.' -ForegroundColor Yellow
        Write-Host 'Sign a release with scripts/windows/sign.ps1. See docs/desktop/WINDOWS_RELEASE.md.' -ForegroundColor Yellow
    }
}
finally {
    Pop-Location
}
