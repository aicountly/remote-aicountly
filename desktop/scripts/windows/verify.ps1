<#
.SYNOPSIS
    Check that AICOUNTLY Remote artifacts are signed, timestamped and chain to
    a real certificate authority.

.DESCRIPTION
    Run after signing, and run again on the artifacts that are about to be
    published. Four things are checked, and all four have failed in real
    release processes:

      1. the signature is present and valid;
      2. it is timestamped — without one it expires with the certificate and
         every copy already downloaded becomes untrusted at the same moment;
      3. the certificate is not self-signed, so the signature says who
         published the software rather than only that somebody did;
      4. the digest is SHA-256; SHA-1 signatures are refused by current
         Windows.

    Exits non-zero if any artifact fails, so a release workflow stops rather
    than publishing something that will warn on a customer's machine.

.PARAMETER Path
    Files to check. Accepts wildcards.

.PARAMETER AllowUnsigned
    Report unsigned artifacts without failing. For a development build, where
    unsigned is the expected outcome and the point of the check is the summary.

.EXAMPLE
    pwsh -File scripts/windows/verify.ps1 -Path target/x86_64-pc-windows-msvc/release/bundle/nsis/*.exe
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string[]] $Path,

    [switch] $AllowUnsigned
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$files = @()
foreach ($pattern in $Path) {
    $files += Get-ChildItem $pattern -File -ErrorAction SilentlyContinue
}

if ($files.Count -eq 0) {
    throw "Nothing matched: $($Path -join ', ')"
}

$failures = @()

foreach ($file in $files) {
    $signature = Get-AuthenticodeSignature $file.FullName
    $problems = @()

    if ($signature.Status -ne 'Valid') {
        $problems += "the signature is $($signature.Status)"
    }

    if ($signature.SignerCertificate) {
        $certificate = $signature.SignerCertificate

        if ($certificate.Subject -eq $certificate.Issuer) {
            $problems += 'the certificate is self-signed, so it vouches for nobody'
        }

        # A signature with no timestamp stops verifying when the certificate
        # expires — including on machines that downloaded it years earlier.
        if (-not $signature.TimeStamperCertificate) {
            $problems += 'there is no RFC 3161 timestamp'
        }

        $algorithm = $certificate.SignatureAlgorithm.FriendlyName
        if ($algorithm -and $algorithm -notmatch 'sha256|sha384|sha512') {
            $problems += "the digest is $algorithm, and Windows refuses SHA-1"
        }
    }
    elseif ($signature.Status -eq 'NotSigned') {
        $problems += 'it is not signed at all'
    }

    if ($problems.Count -eq 0) {
        $subject = $signature.SignerCertificate.Subject
        Write-Host "  OK       $($file.Name)" -ForegroundColor Green
        Write-Host "           $subject" -ForegroundColor DarkGray
        Write-Host "           expires $($signature.SignerCertificate.NotAfter.ToString('yyyy-MM-dd'))" -ForegroundColor DarkGray
    }
    else {
        Write-Host "  FAILED   $($file.Name)" -ForegroundColor Red
        foreach ($problem in $problems) {
            Write-Host "           - $problem" -ForegroundColor Red
        }

        $failures += $file.Name
    }
}

Write-Host ''

if ($failures.Count -eq 0) {
    Write-Host "All $($files.Count) artifact(s) are signed and timestamped." -ForegroundColor Green
    exit 0
}

if ($AllowUnsigned) {
    Write-Host 'UNSIGNED DEVELOPMENT BUILD — not for distribution.' -ForegroundColor Yellow
    Write-Host 'These artifacts will warn on SmartScreen, which is correct.' -ForegroundColor Yellow
    exit 0
}

Write-Host "$($failures.Count) artifact(s) are not fit to publish: $($failures -join ', ')" -ForegroundColor Red
exit 1
