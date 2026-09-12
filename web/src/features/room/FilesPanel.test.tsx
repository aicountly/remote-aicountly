import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import FilesPanel from './FilesPanel'
import type { TransferView } from '../../services/webrtc/fileTransfer'
import type { EnginePeer } from '../../services/webrtc/RemoteSessionEngine'

/**
 * The file panel (§36).
 *
 * What is asserted here is not the layout — it is the two promises the panel
 * makes to the person using it: **nothing arrives without being accepted**, and
 * **nothing reaches the disk without Save being pressed**. Both are properties
 * of the interface, not only of the protocol, so both belong in a test.
 */

function transfer(overrides: Partial<TransferView> = {}): TransferView {
  return {
    uuid: 'transfer-1',
    direction: 'incoming',
    fileName: 'trial-balance.pdf',
    fileSize: 4096,
    mimeType: 'application/pdf',
    phase: 'offered',
    bytesTransferred: 0,
    progress: 0,
    peerId: 'peer-1',
    peerName: 'Priya Nair',
    error: null,
    blob: null,
    ...overrides,
  }
}

function peer(overrides: Partial<EnginePeer> = {}): EnginePeer {
  return {
    participantUuid: 'peer-1',
    role: 'VIEWER',
    name: 'Priya Nair',
    capabilities: {},
    connectionState: 'connected',
    dataChannelReady: true,
    controlChannelReady: false,
    ...overrides,
  }
}

function panel(props: Partial<Parameters<typeof FilesPanel>[0]> = {}) {
  const handlers = {
    onOffer: vi.fn().mockResolvedValue(undefined),
    onAccept: vi.fn().mockResolvedValue(undefined),
    onDecline: vi.fn().mockResolvedValue(undefined),
    onCancel: vi.fn().mockResolvedValue(undefined),
    onDismiss: vi.fn(),
  }

  render(
    <FilesPanel
      transfers={[]}
      peers={[peer()]}
      canSend
      canReceive
      maxBytes={25 * 1024 * 1024}
      {...handlers}
      {...props}
    />,
  )

  return handlers
}

describe('FilesPanel', () => {
  it('says where files actually go, because most people assume the opposite', () => {
    panel()

    expect(screen.getByText(/never receives or stores them/i)).toBeInTheDocument()
  })

  it('presents an incoming file as an offer, never as an arrival', async () => {
    const handlers = panel({ transfers: [transfer()] })

    expect(screen.getByText(/From Priya Nair/)).toBeInTheDocument()
    expect(screen.getByText(/Waiting for you to accept/)).toBeInTheDocument()

    // There is nothing to save, because nothing has been received.
    expect(screen.queryByRole('button', { name: /save/i })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /accept/i }))
    expect(handlers.onAccept).toHaveBeenCalledWith('transfer-1')
  })

  it('declines without accepting anything', async () => {
    const handlers = panel({ transfers: [transfer()] })

    await userEvent.click(screen.getByRole('button', { name: /decline/i }))

    expect(handlers.onDecline).toHaveBeenCalledWith('transfer-1')
    expect(handlers.onAccept).not.toHaveBeenCalled()
  })

  it('holds a received file until Save is pressed', () => {
    panel({
      transfers: [
        transfer({ phase: 'completed', progress: 100, bytesTransferred: 4096, blob: new Blob(['x']) }),
      ],
    })

    expect(screen.getByText(/Received — not saved yet/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument()
  })

  it('refuses a file over the ceiling before anything is offered', async () => {
    const handlers = panel({ maxBytes: 1024 })

    const input = document.getElementById('file-input') as HTMLInputElement
    await userEvent.upload(input, new File(['x'.repeat(4096)], 'big.bin'))

    expect(await screen.findByRole('alert')).toHaveTextContent(/most you can send is/i)
    expect(handlers.onOffer).not.toHaveBeenCalled()
  })

  it('cannot send when nobody is connected', () => {
    panel({ peers: [peer({ dataChannelReady: false })] })

    expect(screen.getByRole('button', { name: /choose a file/i })).toBeDisabled()
    expect(screen.getByText(/nowhere to send a file/i)).toBeInTheDocument()
  })

  it('offers no way to send when the permission is missing, and says so', () => {
    panel({ canSend: false, canReceive: true })

    expect(screen.queryByRole('button', { name: /choose a file/i })).not.toBeInTheDocument()
    expect(screen.getByText(/receive files in this session, but not send/i)).toBeInTheDocument()
  })

  it('names who a file is going to when more than one person could receive it', async () => {
    const handlers = panel({
      peers: [peer(), peer({ participantUuid: 'peer-2', name: 'Aman Verma' })],
    })

    // Guessing between two people is how a file reaches the wrong one.
    expect(screen.getByRole('button', { name: /choose a file/i })).toBeDisabled()

    await userEvent.selectOptions(screen.getByLabelText('Send to'), 'peer-2')
    expect(screen.getByRole('button', { name: /choose a file/i })).toBeEnabled()

    await userEvent.upload(document.getElementById('file-input') as HTMLInputElement, new File(['x'], 'a.txt'))

    expect(handlers.onOffer).toHaveBeenCalledWith(expect.any(File), 'peer-2')
  })
})
