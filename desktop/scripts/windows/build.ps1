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

    Without `-Sign` it signs nothing, and a developer's build needs no
    credential of any kind.

    With `-Sign` it hands `sign.ps1` to the Tauri bundler as its signing
    command, which is what makes a release signed *all the way through*. This
    is not cosmetic. The bundler signs, in this order: the application
    binary, the service sidecar, the NSIS plugin DLLs, the uninstaller, and
    then the installer itself — each one before it is packaged into the next.
    Signing the installer afterwards, which is what this script used to leave
    the release workflow to do, produced a correctly signed installer
    containing two unsigned executables: SmartScreen warned on every launch
    of the installed application, and no check in the release caught it
    because the file being verified was the one file that *was* signed.

    `sign.ps1` still owns every credential and still refuses to run without
    one, so `-Sign` on a machine with nothing configured fails at the first
    binary rather than producing something unsigned and quiet.

.PARAMETER Configuration
    `release` (default) or `debug`.

.PARAMETER Target
    The Rust target triple. Defaults to the host's.

.PARAMETER SkipFrontend
    Skip `npm ci`. For a rebuild where node_modules is already correct.

.PARAMETER Sign
    Sign every binary and the installer as they are built, through
    `scripts/windows/sign.ps1`. Needs that script's credentials in the
    environment.

.EXAMPLE
    pwsh -File scripts/windows/build.ps1

.EXAMPLE
    pwsh -File scripts/windows/build.ps1 -Sign
#>

[CmdletBinding()]
param(
    [ValidateSet('release', 'debug')]
    [string] $Configuration = 'release',

    [string] $Target = 'x86_64-pc-windows-msvc',

    [switch] $SkipFrontend,

    [switch] $Sign
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

    # '--' after the script name stops npm from swallowing '--target'
    # as its own (node-gyp-era) config flag instead of forwarding it to
    # the tauri CLI — without it, npm silently drops '--target' and
    # leaves the bare triple as an unrecognised positional argument that
    # reaches `cargo build` and fails.
    $tauriArgs = @('run', 'tauri', '--', 'build', '--target', $Target)
    if ($Configuration -eq 'debug') { $tauriArgs += '--debug' }

    $signLog = $null

    if ($Sign) {
        $signScript = Join-Path $desktopRoot 'scripts/windows/sign.ps1'
        if (-not (Test-Path $signScript)) {
            throw "The signing script is missing: $signScript"
        }

        # The object form of signCommand, not the string form: Tauri splits
        # the string form on spaces, and this path contains them on any
        # machine whose checkout lives under a folder with a space in it.
        #
        # Absolute, because the bundler runs this command from more than one
        # working directory — the uninstaller is signed from inside makensis,
        # through NSIS's !uninstfinalize, which runs somewhere else entirely.
        $signCommand = @{
            cmd  = 'pwsh'
            args = @('-NoProfile', '-NonInteractive', '-File', $signScript, '-Path', '%1')
        }

        $overlay = @{ bundle = @{ windows = @{ signCommand = $signCommand } } } |
            ConvertTo-Json -Depth 8 -Compress

        # The bundler captures the signing command's output and logs it at
        # debug level, so a signtool failure inside it surfaces as `failed to
        # run pwsh` and nothing else -- which is how a release run failed with
        # no cause attached. sign.ps1 writes everything here as well, and this
        # prints it whether the build succeeded or not: a signature is the one
        # part of a release nobody can inspect afterwards without the log.
        $signLog = Join-Path $desktopRoot "target/$Target/$Configuration/signing.log"
        New-Item -ItemType Directory -Force -Path (Split-Path $signLog) | Out-Null
        Remove-Item $signLog -Force -ErrorAction SilentlyContinue
        $env:AICOUNTLY_SIGN_LOG = $signLog

        Write-Host ''
        Write-Host 'Signing is on: every binary and the installer.' -ForegroundColor Cyan
        Write-Host "  sign command: pwsh -File $signScript -Path <file>"
        Write-Host "  signing log:  $signLog"

        $tauriArgs += @('--config', $overlay)
    }

    & npm @tauriArgs
    $tauriExit = $LASTEXITCODE

    if ($signLog -and (Test-Path $signLog)) {
        Write-Host ''
        Write-Host '----- signing log -----' -ForegroundColor DarkGray
        Get-Content $signLog | ForEach-Object { Write-Host $_ }
        Write-Host '----- end of signing log -----' -ForegroundColor DarkGray
        Write-Host ''
    }

    if ($tauriExit -ne 0) { throw 'The application did not build.' }

    # ----------------------------------------------------------------- the report
    #
    # The installers *and* the two executables that go inside them. A report
    # that only looked at the installer is how an installer full of unsigned
    # binaries passed for a signed release.
    $bundle = Join-Path $desktopRoot "target/$Target/$Configuration/bundle"
    $artifacts = @()
    foreach ($pattern in @('nsis/*.exe')) {
        $artifacts += Get-ChildItem (Join-Path $bundle $pattern) -ErrorAction SilentlyContinue
    }

    foreach ($binary in @("target/$Target/$Configuration/AicountlyRemote.exe",
                          "src-tauri/binaries/AicountlyRemoteService-$Target.exe")) {
        $artifacts += Get-ChildItem (Join-Path $desktopRoot $binary) -ErrorAction SilentlyContinue
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
