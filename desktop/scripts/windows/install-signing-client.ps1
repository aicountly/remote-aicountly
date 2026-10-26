<#
.SYNOPSIS
    Install the Microsoft Trusted Signing client and locate its dlib.

.DESCRIPTION
    `signtool /dlib` needs the path to Azure.CodeSigning.Dlib.dll. The client
    is installed at release time rather than committed, because a signing
    client is somebody else's software to keep current -- which makes its
    layout somebody else's to change as well.

    That is not hypothetical. This logic used to live in the release workflow
    and joined `Bin/x64/Azure.CodeSigning.Dlib.dll` onto the module base;
    TrustedSigning 0.5.8 does not keep the dlib there, and a release that had
    passed the whole gate died on the step after it. So the dlib is searched
    for. If the module does not carry it, the client is also published as a
    NuGet package and is fetched from the flat container directly -- no
    nuget.exe and no package provider, neither of which is guaranteed on a
    runner image. If neither route finds it, the failure prints what the
    module actually contains, so the next reader sees the layout rather than
    guessing at it again.

    It lives here rather than in YAML so that the release workflow and the
    signing smoke test run the same code, and so that it can be read and
    changed as a script.

.OUTPUTS
    The dlib's path, and `AICOUNTLY_SIGN_DLIB=<path>` appended to GITHUB_ENV
    when there is one, which is where sign.ps1 reads it from.
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

Install-Module -Name TrustedSigning -Force -Scope CurrentUser -AllowClobber

$module = Get-Module -ListAvailable TrustedSigning |
  Sort-Object Version -Descending | Select-Object -First 1
if (-not $module) { throw 'The TrustedSigning module did not install.' }
Write-Host "TrustedSigning $($module.Version): $($module.ModuleBase)"

function Find-Dlib {
    param([string] $Root)

    if (-not $Root -or -not (Test-Path $Root)) { return $null }

    # signtool and the dlib have to be the same architecture, and
    # the runner's signtool is x64 — so an x64 copy wins over any
    # other that happens to be in the same tree.
    Get-ChildItem -Path $Root -Recurse -File -Filter 'Azure.CodeSigning.Dlib.dll' -ErrorAction SilentlyContinue |
        Sort-Object @{ Expression = { $_.FullName -match '[\\/]x64[\\/]' }; Descending = $true } |
        Select-Object -First 1 -ExpandProperty FullName
}

$dlib = Find-Dlib $module.ModuleBase

if (-not $dlib) {
    Write-Host 'Not in the module. Fetching the NuGet signing client.'

    $scratch = if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [System.IO.Path]::GetTempPath() }
    $temp = Join-Path $scratch 'signing-client'
    New-Item -ItemType Directory -Force -Path $temp | Out-Null

    # Trusted Signing was renamed Azure Artifact Signing, and the
    # client package went with it. Both ids are tried: whichever
    # of them this account's tooling is current with.
    foreach ($id in @('Microsoft.Trusted.Signing.Client', 'Microsoft.ArtifactSigning.Client')) {
        try {
            $package = $id.ToLowerInvariant()
            $feed = "https://api.nuget.org/v3-flatcontainer/$package"

            $version = (Invoke-RestMethod "$feed/index.json").versions |
                Where-Object { $_ -notmatch '-' } | Select-Object -Last 1
            if (-not $version) { continue }

            $archive = Join-Path $temp "$package.$version.zip"
            $expanded = Join-Path $temp "$package-$version"

            Invoke-WebRequest "$feed/$version/$package.$version.nupkg" -OutFile $archive
            Expand-Archive -Path $archive -DestinationPath $expanded -Force

            Write-Host "$id $version"
            $dlib = Find-Dlib $expanded
            if ($dlib) { break }
        } catch {
            Write-Host "$id : $($_.Exception.Message)"
        }
    }
}

if (-not $dlib) {
    Write-Host '--- TrustedSigning module contents ---'
    Get-ChildItem -Path $module.ModuleBase -Recurse -File |
        ForEach-Object { Write-Host $_.FullName.Substring($module.ModuleBase.Length) }

    throw 'Azure.CodeSigning.Dlib.dll was not found in the TrustedSigning module or in the NuGet signing client.'
}

Write-Host "dlib: $dlib"

# Running outside Actions (a developer checking the client by hand) is fine:
# the path is printed either way, and only the environment file is skipped.
if ($env:GITHUB_ENV) {
    "AICOUNTLY_SIGN_DLIB=$dlib" | Out-File -FilePath $env:GITHUB_ENV -Append -Encoding utf8
} else {
    Write-Host 'GITHUB_ENV is not set, so nothing was exported. Set AICOUNTLY_SIGN_DLIB to the path above.'
}
