import { useMemo } from 'react'
import qrcode from 'qrcode-generator'

/**
 * A QR code, drawn here as SVG.
 *
 * The encoding is `qrcode-generator` (pinned to an exact version in
 * package.json, no dependencies of its own); the drawing is ours, from its
 * module matrix, so nothing is injected as HTML and nothing is fetched from
 * another site — the secret in an `otpauth://` URI must not leave the page to
 * be turned into a picture somewhere else.
 *
 * Error correction M and the four-module quiet zone the standard asks for:
 * what phone cameras read reliably off a laptop screen.
 */

interface Props {
  value: string
  /** What a screen reader says instead of a picture it cannot read. */
  label: string
  size?: number
}

const QUIET_ZONE = 4

export default function QrCode({ value, label, size = 200 }: Props) {
  const { count, path } = useMemo(() => {
    const code = qrcode(0, 'M')
    code.addData(value)
    code.make()

    const modules = code.getModuleCount()
    let d = ''
    for (let row = 0; row < modules; row++) {
      for (let col = 0; col < modules; col++) {
        if (code.isDark(row, col)) d += `M${col + QUIET_ZONE} ${row + QUIET_ZONE}h1v1h-1z`
      }
    }

    return { count: modules, path: d }
  }, [value])

  const extent = count + QUIET_ZONE * 2

  return (
    <svg
      className="qr-code"
      role="img"
      aria-label={label}
      width={size}
      height={size}
      viewBox={`0 0 ${extent} ${extent}`}
      shapeRendering="crispEdges"
      data-modules={count}
    >
      <rect width={extent} height={extent} fill="#ffffff" />
      <path d={path} fill="#000000" />
    </svg>
  )
}
