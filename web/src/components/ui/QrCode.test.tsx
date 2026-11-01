import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import qrcode from 'qrcode-generator'

import QrCode from './QrCode'

/**
 * The QR code an authenticator app scans is drawn in the page, from the
 * encoder's own module matrix — not fetched from anywhere, and not injected
 * as HTML.
 */
describe('QrCode', () => {
  const URI = 'otpauth://totp/AICOUNTLY%20Remote:meera%40example.test?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=AICOUNTLY%20Remote&algorithm=SHA1&digits=6&period=30'

  it('draws exactly the dark modules the encoder made, inside a quiet zone', () => {
    render(<QrCode value={URI} label="QR code" />)

    const svg = screen.getByRole('img', { name: 'QR code' })
    const reference = qrcode(0, 'M')
    reference.addData(URI)
    reference.make()
    const count = reference.getModuleCount()

    let dark = 0
    for (let row = 0; row < count; row++) for (let col = 0; col < count; col++) if (reference.isDark(row, col)) dark++

    expect(svg.getAttribute('viewBox')).toBe(`0 0 ${count + 8} ${count + 8}`)
    expect(svg.querySelector('path')!.getAttribute('d')!.match(/M/g)).toHaveLength(dark)
    // The finder pattern's top-left corner sits right after the four-module quiet zone.
    expect(svg.querySelector('path')!.getAttribute('d')).toMatch(/^M4 4h1v1h-1z/)
  })

  it('has no markup it was not given as data', () => {
    const { container } = render(<QrCode value={'<script>alert(1)</script>'} label="QR code" />)

    expect(container.querySelector('script')).toBeNull()
    expect(container.innerHTML).not.toContain('alert')
  })
})
