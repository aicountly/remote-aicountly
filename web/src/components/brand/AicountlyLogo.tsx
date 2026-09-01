import { useState } from 'react'

/**
 * The AICOUNTLY logo.
 *
 * **This component deliberately contains no logo artwork.** The specification
 * is explicit that Remote must use the real AICOUNTLY asset and must not
 * redraw, distort or invent a replacement (§46) — and this repository ships no
 * brand assets, so drawing one here would be inventing it.
 *
 * Instead: drop the official file at `web/public/brand/aicountly-logo.svg` and
 * it appears everywhere. Until then the product shows the plain wordmark in the
 * interface typeface, which is a typographic fallback rather than a mark.
 *
 * See docs/BRANDING.md.
 */

const LOGO_SRC = '/brand/aicountly-logo.svg'

export default function AicountlyLogo({ height = 22 }: { height?: number }) {
  const [assetMissing, setAssetMissing] = useState(false)

  if (assetMissing) {
    return (
      <span className="brand-wordmark" aria-label="AICOUNTLY">
        AICOUNTLY
      </span>
    )
  }

  return (
    <img
      src={LOGO_SRC}
      alt="AICOUNTLY"
      height={height}
      className="brand-logo"
      // The fallback is the point: a missing asset must not leave a broken
      // image icon in the header of every screen.
      onError={() => setAssetMissing(true)}
    />
  )
}

/**
 * The Remote product mark.
 *
 * This one *is* ours to draw: §46 asks for "a subtle Remote product identifier
 * created separately" alongside the AICOUNTLY logo. A rounded screen outline
 * with a signal arc — screen sharing, at 20px, with no text.
 */
export function RemoteMark({ size = 20 }: { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <rect x="2.5" y="4" width="19" height="13" rx="2.5" />
      <path d="M8.5 20.5h7" />
      <path d="M12 17v3.5" />
      <path d="M9.5 12.5a3.5 3.5 0 0 1 5 0" />
      <path d="M7.5 10a6.5 6.5 0 0 1 9 0" />
    </svg>
  )
}
