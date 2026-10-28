/**
 * Where AICOUNTLY Remote for Windows is offered: aicountly.com/remote, which
 * lists the signed builds on downloads.aicountly.com
 * (docs/desktop/PUBLISHING.md). There is no sandbox download page, so every
 * host — production, sandbox and local — links here.
 */
export const DESKTOP_APP_DOWNLOAD_URL = 'https://aicountly.com/remote'

/**
 * "Download AICOUNTLY Remote for Windows". Always a new tab: in a session room a
 * link that replaced the page would leave the session.
 */
export function DesktopAppDownloadLink({ className }: { className?: string }) {
  return (
    <a className={className} href={DESKTOP_APP_DOWNLOAD_URL} target="_blank" rel="noopener noreferrer">
      Download AICOUNTLY Remote for Windows
      <span className="sr-only"> (opens in a new tab)</span>
    </a>
  )
}
