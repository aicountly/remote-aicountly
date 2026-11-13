import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { CodecView, HostedParticipant } from '../../types/agent'
import { GetHelp, HelpAdmission } from './GetHelp'

const HELP = { uuid: 'help-1', displayId: 'AR-1', sessionCode: '123456789', status: 'WAITING' }

function person(overrides: Partial<HostedParticipant> = {}): HostedParticipant {
  return {
    uuid: 'p-1',
    displayName: 'Sam',
    role: 'VIEWER',
    status: 'WAITING',
    requestedAt: null,
    joinedAt: null,
    ...overrides,
  }
}

function props(overrides: Partial<Parameters<typeof GetHelp>[0]> = {}) {
  return {
    computerName: 'computer',
    help: null,
    hosting: false,
    participants: null,
    canHost: true,
    needsScreenRecording: false,
    onOpenScreenRecording: vi.fn(),
    onStart: vi.fn(),
    onAnswer: vi.fn(),
    onEnd: vi.fn(),
    ...overrides,
  }
}

describe('GetHelp', () => {
  it('says what happens before a code is asked for', async () => {
    const onStart = vi.fn()

    render(<GetHelp {...props({ onStart })} />)

    expect(screen.getByText(/they see your screen/i)).toBeInTheDocument()
    expect(screen.getByText(/only give the code to somebody you know/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Get a code' }))
    expect(onStart).toHaveBeenCalledOnce()
  })

  it('shows the code, who is waiting, and lets the person decide', async () => {
    const onAnswer = vi.fn()
    const onEnd = vi.fn()

    render(
      <GetHelp
        {...props({
          help: HELP,
          hosting: true,
          participants: { waiting: [person()], joined: [person({ uuid: 'p-2', displayName: 'Priya', status: 'JOINED' })] },
          onAnswer,
          onEnd,
        })}
      />,
    )

    expect(screen.getByText('123 456 789')).toBeInTheDocument()
    expect(screen.getByText('Priya')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Let in' }))
    expect(onAnswer).toHaveBeenCalledWith(expect.objectContaining({ uuid: 'p-1' }), true)

    await userEvent.click(screen.getByRole('button', { name: 'Turn away' }))
    expect(onAnswer).toHaveBeenCalledWith(expect.objectContaining({ uuid: 'p-1' }), false)

    // The same words as the button at the top of the window, which does the same thing.
    await userEvent.click(screen.getByRole('button', { name: 'End session' }))
    expect(onEnd).toHaveBeenCalledOnce()
  })

  /**
   * "They type it under Join session" named a menu and not the place the
   * menu is; and a code that can only be read aloud cannot be sent in a
   * message, which is how most people would rather send nine digits.
   */
  it('says where the helper types the code, and lets it be copied', async () => {
    const user = userEvent.setup()
    const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)

    render(<GetHelp {...props({ help: HELP, hosting: true, remoteAddress: 'remote.aicountly.com' })} />)

    expect(screen.getByText('remote.aicountly.com')).toBeInTheDocument()
    expect(screen.getByText(/stops working when you end the session/i)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Copy code' }))

    expect(writeText).toHaveBeenCalledWith('123 456 789')
    expect(await screen.findByText('Copied')).toBeInTheDocument()
  })

  /** Offline, "Get a code" used to stay enabled and fail with "Something went wrong." */
  it('does not offer a code it cannot make while this computer is offline, and says why', () => {
    render(<GetHelp {...props({ offline: true })} />)

    expect(screen.getByRole('button', { name: 'Get a code' })).toBeDisabled()
    expect(screen.getByText(/is not connected to AICOUNTLY Remote at the moment/i)).toBeInTheDocument()
  })

  /** The stop button on this card is never greyed out by something else in flight. */
  it('lets the session be ended even while something else is still in flight', () => {
    render(<GetHelp {...props({ help: HELP, hosting: true, busy: true })} />)

    expect(screen.getByRole('button', { name: 'End session' })).toBeEnabled()
  })

  it('asks for Screen Recording on a Mac that has not allowed it', async () => {
    const onOpenScreenRecording = vi.fn()

    render(<GetHelp {...props({ computerName: 'Mac', needsScreenRecording: true, onOpenScreenRecording })} />)

    await userEvent.click(screen.getByRole('button', { name: 'Open Screen Recording settings' }))
    expect(onOpenScreenRecording).toHaveBeenCalledOnce()
  })

  it('offers nothing it cannot do on a build that cannot show the screen', () => {
    render(<GetHelp {...props({ canHost: false })} />)

    expect(screen.queryByRole('button', { name: 'Get a code' })).not.toBeInTheDocument()
    expect(screen.getByText(/cannot show this computer/i)).toBeInTheDocument()
  })

  /**
   * Without the video codec the helper would see nothing: said before
   * anybody is given a code, with the setting that fixes it.
   */
  it('warns that the helper would see no picture while the codec is not ready', async () => {
    const onOpenSettings = vi.fn()
    const codec = (status: CodecView['status']): CodecView => ({
      enabled: status.state !== 'disabled',
      status,
      attribution: 'OpenH264 Video Codec provided by Cisco Systems, Inc.',
      version: '2.6.0',
      file: 'openh264-2.6.0-win64.dll',
    })

    const { rerender } = render(<GetHelp {...props({ codec: codec({ state: 'disabled' }), onOpenSettings })} />)

    expect(screen.getByText('The person helping you would not see your screen')).toBeInTheDocument()
    expect(screen.getByText('OpenH264 Video Codec provided by Cisco Systems, Inc.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Open App settings' }))
    expect(onOpenSettings).toHaveBeenCalledOnce()
    expect(screen.getByRole('button', { name: 'Get a code' })).toBeInTheDocument()

    rerender(
      <GetHelp {...props({ codec: codec({ state: 'ready', version: '2.6.0', path: 'x', sizeBytes: 1 }), onOpenSettings })} />,
    )
    expect(screen.queryByText('The person helping you would not see your screen')).not.toBeInTheDocument()
  })
})

describe('HelpAdmission', () => {
  it('asks about the first person waiting, by name', async () => {
    const onAnswer = vi.fn()

    render(<HelpAdmission waiting={[person(), person({ uuid: 'p-3', displayName: 'Lee' })]} onAnswer={onAnswer} />)

    expect(screen.getByText('Sam wants to join your help session')).toBeInTheDocument()
    expect(screen.getByText(/1 more waiting/)).toBeInTheDocument()
    // The scam case: a stranger who was read a code over the phone.
    expect(screen.getByText(/only let in somebody you know and gave your code to/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Let Sam in' }))
    expect(onAnswer).toHaveBeenCalledWith(expect.objectContaining({ uuid: 'p-1' }), true)
  })

  it('is not there when nobody is waiting', () => {
    const { container } = render(<HelpAdmission waiting={[]} onAnswer={vi.fn()} />)

    expect(container).toBeEmptyDOMElement()
  })
})
