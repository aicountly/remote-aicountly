import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { OwnComputer } from '../../types/agent'

const bridge = vi.hoisted(() => ({ listComputers: vi.fn() }))

vi.mock('../../services/tauri', () => bridge)

const { YourComputers, connectability, describeComputers } = await import('./YourComputers')

function computer(overrides: Partial<OwnComputer> = {}): OwnComputer {
  return {
    uuid: 'c-1',
    deviceName: 'Office PC',
    operatingSystem: 'Windows',
    osVersion: '11',
    status: 'ACTIVE',
    online: true,
    lastSeenAt: '2026-10-30T09:00:00Z',
    enrolledAt: '2026-10-01T09:00:00Z',
    unattendedAccessEnabled: true,
    isThisComputer: false,
    ...overrides,
  }
}

beforeEach(() => {
  bridge.listComputers.mockReset()
})

describe('describeComputers', () => {
  it('counts what is registered and what is online', () => {
    expect(describeComputers({ computers: [], count: 0 })).toBe('None registered yet.')
    expect(describeComputers({ computers: [computer()], count: 1 })).toBe('1 computer registered · 1 online')
    expect(
      describeComputers({ computers: [computer(), computer({ uuid: 'c-2', online: false })], count: 2 }),
    ).toBe('2 computers registered · 1 online')
  })
})

describe('connectability', () => {
  it('connects only to another computer that is online with unattended access on', () => {
    expect(connectability(computer())).toEqual({ canConnect: true, reason: null })
    expect(connectability(computer({ isThisComputer: true })).canConnect).toBe(false)
    expect(connectability(computer({ unattendedAccessEnabled: false })).reason).toMatch(/unattended access is off/i)
    expect(connectability(computer({ online: false })).reason).toMatch(/offline · last seen/i)
  })
})

describe('YourComputers', () => {
  it('lists the computers with a count, and connects to one', async () => {
    const onConnect = vi.fn()
    bridge.listComputers.mockResolvedValue({
      computers: [
        computer({ uuid: 'this', deviceName: 'This laptop', isThisComputer: true }),
        computer({ uuid: 'office', deviceName: 'Office PC' }),
        computer({ uuid: 'home', deviceName: 'Home Mac', operatingSystem: 'macOS', online: false }),
      ],
      count: 3,
    })

    render(<YourComputers onConnect={onConnect} onManage={vi.fn()} />)

    expect(await screen.findByText('3 computers registered · 2 online')).toBeInTheDocument()
    expect(screen.getByText('This computer')).toBeInTheDocument()

    const buttons = screen.getAllByRole('button', { name: 'Connect' })
    // Not offered for this computer itself; the offline one cannot be pressed.
    expect(buttons).toHaveLength(2)
    expect(buttons[1]).toBeDisabled()

    await userEvent.click(buttons[0]!)
    expect(onConnect).toHaveBeenCalledWith(expect.objectContaining({ uuid: 'office' }))
  })

  it('says why the list could not be read', async () => {
    bridge.listComputers.mockRejectedValue('This computer is not signed in to AICOUNTLY Remote yet.')

    render(<YourComputers onConnect={vi.fn()} onManage={vi.fn()} />)

    await waitFor(() => expect(screen.getByText(/not signed in to aicountly remote yet/i)).toBeInTheDocument())
  })

  it('sends the person to Remote → Computers to manage or remove one', async () => {
    const onManage = vi.fn()
    bridge.listComputers.mockResolvedValue({ computers: [], count: 0 })

    render(<YourComputers onConnect={vi.fn()} onManage={onManage} />)

    await userEvent.click(await screen.findByRole('button', { name: /remove a computer/i }))
    expect(onManage).toHaveBeenCalledOnce()
  })
})
