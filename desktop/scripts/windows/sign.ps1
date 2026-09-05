<#
.SYNOPSIS
    Authenticode-sign the AICOUNTLY Remote binaries and installers.

.DESCRIPTION
    Signing is separate from building on purpose. This script is the only one
    that touches a signing credential, it takes every one of them from the
    environment rather than from a file or a parameter default, and it refuses
    to run rather than falling back to something weaker.

    Two supported paths, and the script picks whichever the environment
    configures:

      * **Microsoft Trusted Signing** (formerly Azure Code Signing) — the
        recommended path. Nothing to store: the key never exists outside
        Microsoft's HSM, and there is no certificate file to leak, rotate by
        hand, or find on somebody's laptop three years later. Configured with
        AICOUNTLY_SIGN_METHOD=trusted-signing plus the Azure variables below.

      * **An OV/EV Authenticode certificate** in the Windows certificate store,
        addressed by thumbprint. Configured with
        AICOUNTLY_SIGN_METHOD=certificate and AICOUNTLY_SIGN_THUMBPRINT.
        The certificate itself is never a repository file and never a
        workflow input; it is installed on the runner by the environment.

    A self-signed certificate is NOT a supported path and this script will not
    use one. A self-signed signature satisfies a signature *check* while
    telling a customer's machine nothing about who published the software,
    which is worse than being honestly unsigned.

    Everything signed is timestamped (RFC 3161). Without a timestamp a
    signature stops verifying the day the certificate expires, and every
    installer already downloaded becomes untrusted at once.

.PARAMETER Path
    Files to sign. Accepts wildcards.

.EXAMPLE
    pwsh -File scripts/windows/sign.ps1 -Path target/x86_64-pc-windows-msvc/release/bundle/nsis/*.exe
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string[]] $Path
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# RFC 3161. A signature without one expires with the certificate; with one it
# keeps verifying for the life of the timestamp authority's own certificate.
$timestampUrl = if ($env:AICOUNTLY_SIGN_TIMESTAMP_URL) {
    $env:AICOUNTLY_SIGN_TIMESTAMP_URL
} else {
    'http://timestamp.digicert.com'
}

$method = $env:AICOUNTLY_SIGN_METHOD

if (-not $method) {
    throw @'
No signing method is configured, so nothing was signed.

Set AICOUNTLY_SIGN_METHOD to one of:

  trusted-signing   Microsoft Trusted Signing. Also set:
                      AZURE_TENANT_ID, AZURE_CLIENT_ID, AZURE_CLIENT_SECRET
                      AICOUNTLY_SIGN_ENDPOINT      e.g. https://eus.codesigning.azure.net
                      AICOUNTLY_SIGN_ACCOUNT
                      AICOUNTLY_SIGN_PROFILE

  certificate       An OV/EV Authenticode certificate already installed in the
                    runner's certificate store. Also set:
                      AICOUNTLY_SIGN_THUMBPRINT

Self-signed certificates are not a supported production path. See
docs/desktop/WINDOWS_RELEASE.md.
'@
}

$files = @()
foreach ($pattern in $Path) {
    $files += Get-ChildItem $pattern -File -ErrorAction SilentlyContinue
}

if ($files.Count -eq 0) {
    throw "Nothing matched: $($Path -join ', ')"
}

function Resolve-SignTool {
    <#
        signtool.exe is part of the Windows SDK and is not on PATH by default.
        The newest one found is used: older ones predate the Trusted Signing
        dlib interface.
    #>
    $onPath = Get-Command signtool.exe -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }

    $roots = @(
        "${env:ProgramFiles(x86)}\Windows Kits\10\bin",
        "$env:ProgramFiles\Windows Kits\10\bin"
    ) | Where-Object { $_ -and (Test-Path $_) }

    $candidates = foreach ($root in $roots) {
        Get-ChildItem -Path $root -Recurse -Filter signtool.exe -ErrorAction SilentlyContinue |
            Where-Object { $_.FullName -match '\\x64\\' }
    }

    $newest = $candidates | Sort-Object FullName -Descending | Select-Object -First 1
    if (-not $newest) {
        throw 'signtool.exe was not found. Install the Windows SDK signing tools.'
    }

    return $newest.FullName
}

$signtool = Resolve-SignTool
Write-Host "signtool: $signtool" -ForegroundColor DarkGray

switch ($method) {
    'trusted-signing' {
        foreach ($name in 'AZURE_TENANT_ID', 'AZURE_CLIENT_ID', 'AZURE_CLIENT_SECRET',
                          'AICOUNTLY_SIGN_ENDPOINT', 'AICOUNTLY_SIGN_ACCOUNT', 'AICOUNTLY_SIGN_PROFILE') {
            if (-not (Get-Item "env:$name" -ErrorAction SilentlyContinue)) {
                throw "$name is not set, and Trusted Signing needs it."
            }
        }

        # The dlib is the Trusted Signing client. It is installed by the
        # release workflow rather than committed, because a signing client is
        # somebody else's software to keep current.
        $dlib = $env:AICOUNTLY_SIGN_DLIB
        if (-not $dlib -or -not (Test-Path $dlib)) {
            throw 'AICOUNTLY_SIGN_DLIB must point at Azure.CodeSigning.Dlib.dll.'
        }

        # Written next to the artifacts and deleted afterwards: it names an
        # endpoint, an account and a profile, and no secret. The credential
        # itself stays in the environment where signtool's Azure client reads
        # it.
        $metadata = Join-Path ([System.IO.Path]::GetTempPath()) 'aicountly-trusted-signing.json'
        @{
            Endpoint               = $env:AICOUNTLY_SIGN_ENDPOINT
            CodeSigningAccountName = $env:AICOUNTLY_SIGN_ACCOUNT
            CertificateProfileName = $env:AICOUNTLY_SIGN_PROFILE
        } | ConvertTo-Json | Set-Content -Path $metadata -Encoding utf8

        try {
            foreach ($file in $files) {
                Write-Host "Signing $($file.Name)..." -ForegroundColor Cyan

                & $signtool sign /v /fd SHA256 /tr $timestampUrl /td SHA256 `
                    /dlib $dlib /dmdf $metadata $file.FullName

                if ($LASTEXITCODE -ne 0) { throw "signtool failed on $($file.Name)." }
            }
        }
        finally {
            Remove-Item $metadata -Force -ErrorAction SilentlyContinue
        }
    }

    'certificate' {
        $thumbprint = $env:AICOUNTLY_SIGN_THUMBPRINT
        if (-not $thumbprint) {
            throw 'AICOUNTLY_SIGN_THUMBPRINT is not set.'
        }

        # Refuse a self-signed certificate rather than producing a signature
        # that looks valid and vouches for nobody.
        $certificate = Get-ChildItem Cert:\CurrentUser\My, Cert:\LocalMachine\My -ErrorAction SilentlyContinue |
            Where-Object { $_.Thumbprint -eq $thumbprint } |
            Select-Object -First 1

        if (-not $certificate) {
            throw "No certificate with thumbprint $thumbprint is installed on this machine."
        }

        if ($certificate.Subject -eq $certificate.Issuer) {
            throw @"
The certificate with thumbprint $thumbprint is self-signed.

A self-signed signature satisfies a signature check while telling a customer's
machine nothing about who published the software. It is not a production
signing path here and this script will not use one.
"@
        }

        foreach ($file in $files) {
            Write-Host "Signing $($file.Name)..." -ForegroundColor Cyan

            & $signtool sign /v /fd SHA256 /tr $timestampUrl /td SHA256 `
                /sha1 $thumbprint $file.FullName

            if ($LASTEXITCODE -ne 0) { throw "signtool failed on $($file.Name)." }
        }
    }

    default {
        throw "AICOUNTLY_SIGN_METHOD=$method is not one this script knows."
    }
}

Write-Host ''
Write-Host 'Verifying...' -ForegroundColor Cyan

# Verifying after signing is not ceremony: a signature that did not take, or
# took without a timestamp, is one a customer discovers rather than the release
# process.
& (Join-Path $PSScriptRoot 'verify.ps1') -Path ($files | ForEach-Object { $_.FullName })
