import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { CodecStatus, CodecView } from '../../types/agent'
import { CodecNotice } from './CodecNotice'

/**
 * A session with no codec sends no picture. The person at the computer is
 * told, with the setting that fixes it — never left to find out from the
 * person on the phone.
 */

function codec(status: CodecStatus): CodecView {
  return {
    enabled: status.state !== 'disabled',
    status,
    attribution: 'OpenH264 Video Codec provided by Cisco Systems, Inc.',
    version: '2.6.0',
    file: 'openh264-2.6.0-win64.dll',
  }
}

describe('CodecNotice', () => {
  it('says the helper sees nothing, names the setting, and opens it', async () => {
    const onOpenSettings = vi.fn()

    render(<CodecNotice codec={codec({ state: 'disabled' })} context="session" onOpenSettings={onOpenSettings} />)

    expect(screen.getByRole('alert')).toHaveTextContent('cannot see your screen')
    expect(screen.getByText('OpenH264 Video Codec provided by Cisco Systems, Inc.')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('App settings, under Video codec')

    await userEvent.click(screen.getByRole('button', { name: 'Open App settings' }))
    expect(onOpenSettings).toHaveBeenCalledOnce()
  })

  it('gives the reason a download failed and points at Retry', () => {
    render(
      <CodecNotice
        codec={codec({ state: 'failed', reason: 'Cisco’s server answered 503.' })}
        context="session"
        onOpenSettings={vi.fn()}
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Cisco’s server answered 503.')
    expect(screen.getByRole('alert')).toHaveTextContent('Retry it in App settings')
  })

  it('asks for patience, not a setting, while it downloads', () => {
    render(
      <CodecNotice
        codec={codec({ state: 'downloading', receivedBytes: 1, totalBytes: 2 })}
        context="session"
        onOpenSettings={vi.fn()}
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('starts as soon as it is ready')
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('says nothing once the codec is ready', () => {
    const { container } = render(
      <CodecNotice
        codec={codec({ state: 'ready', version: '2.6.0', path: 'x', sizeBytes: 1 })}
        context="session"
      />,
    )

    expect(container).toBeEmptyDOMElement()
  })
})
