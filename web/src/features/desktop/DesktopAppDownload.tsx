/**
 * Where the AICOUNTLY Remote desktop app is offered: aicountly.com/remote,
 * which lists the builds on downloads.aicountly.com
 * (docs/desktop/PUBLISHING.md). There is no sandbox download page, so every
 * host — production, sandbox and local — links here.
 */
export const DESKTOP_APP_DOWNLOAD_URL = 'https://aicountly.com/remote'

/**
 * "Download the AICOUNTLY Remote app". It names no operating system: the app
 * is built for Windows and for Mac, the page it opens offers what is published
 * for each, and a link that said "for Windows" told the person on a Mac that
 * there was nothing for them. Always a new tab: in a session room a link that
 * replaced the page would leave the session.
 */
export function DesktopAppDownloadLink({ className }: { className?: string }) {
  return (
    <a className={className} href={DESKTOP_APP_DOWNLOAD_URL} target="_blank" rel="noopener noreferrer">
      Download the AICOUNTLY Remote app
      <span className="sr-only"> (opens in a new tab)</span>
    </a>
  )
}
