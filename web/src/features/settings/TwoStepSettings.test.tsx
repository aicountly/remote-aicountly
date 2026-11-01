import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { TwoStepStatus } from '../../types/remote'

/**
 * Settings → Two-step verification: turning it on (scan or type the key,
 * confirm with a code, save the ten recovery codes), turning it off (with a
 * code), new recovery codes, and how many are left.
 */

const api = vi.hoisted(() => ({
  fetchTwoStepStatus: vi.fn(),
  startTwoStepEnrolment: vi.fn(),
  confirmTwoStep: vi.fn(),
  regenerateRecoveryCodes: vi.fn(),
  disableTwoStep: vi.fn(),
}))
vi.mock('../../services/api/remote', () => api)

const { default: TwoStepSettings, groupSecret, recoveryCodesFile } = await import('./TwoStepSettings')

const OFF: TwoStepStatus = {
  enabled: false,
  pending: false,
  available: true,
  confirmedAt: null,
  recoveryCodesLeft: 0,
  sessionVerified: false,
  verifiedAt: null,
  verifiedUntil: null,
  lockedUntil: null,
}

const ON: TwoStepStatus = {
  ...OFF,
  enabled: true,
  confirmedAt: '2026-11-01T09:00:00+00:00',
  recoveryCodesLeft: 10,
  sessionVerified: true,
  verifiedAt: '2026-11-01T09:00:00+00:00',
  verifiedUntil: '2026-11-01T21:00:00+00:00',
}

const CODES = Array.from({ length: 10 }, (_, i) => `ABCDE-2345${i}`)
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'

// A block body: an arrow returning the reset mock would hand Vitest the mock as a teardown hook.
beforeEach(() => {
  Object.values(api).forEach((mock) => mock.mockReset())
})

afterEach(() => {
  // What a test gave the browser is taken away again, so the next one starts without it.
  delete (navigator as unknown as { clipboard?: unknown }).clipboard
  delete (URL as unknown as { createObjectURL?: unknown }).createObjectURL
  delete (URL as unknown as { revokeObjectURL?: unknown }).revokeObjectURL
})

describe('turning two-step verification on', () => {
  it('shows the QR code and the key to type, confirms with a code, then the ten recovery codes once', async () => {
    api.fetchTwoStepStatus.mockResolvedValueOnce(OFF).mockResolvedValueOnce(ON)
    api.startTwoStepEnrolment.mockResolvedValue({
      secret: SECRET,
      otpauthUri: `otpauth://totp/AICOUNTLY%20Remote:meera%40example.test?secret=${SECRET}&issuer=AICOUNTLY%20Remote`,
    })
    api.confirmTwoStep.mockResolvedValue({ recoveryCodes: CODES, verificationToken: 't', verifiedAt: ON.verifiedAt, verifiedUntil: ON.verifiedUntil })
    const writeText = vi.fn(async () => undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    // jsdom has neither; the page needs both to hand over a file.
    const createObjectURL = vi.fn(() => 'blob:codes')
    Object.defineProperty(URL, 'createObjectURL', { value: createObjectURL, configurable: true })
    Object.defineProperty(URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    render(<TwoStepSettings />)

    expect(await screen.findByText(/unattended access needs it/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Turn on two-step verification' }))

    // Scan it, or type it.
    const qr = await screen.findByRole('img', { name: /QR code to add AICOUNTLY Remote/ })
    expect(qr.tagName.toLowerCase()).toBe('svg')
    expect(Number(qr.getAttribute('data-modules'))).toBeGreaterThan(20)
    expect(screen.getByLabelText('Your setup key')).toHaveTextContent('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')
    // No recovery code while confirming: there are none yet.
    expect(screen.queryByRole('button', { name: 'Use a recovery code' })).not.toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Code from your authenticator app'), '123456')
    await userEvent.click(screen.getByRole('button', { name: 'Turn on' }))
    expect(api.confirmTwoStep).toHaveBeenCalledWith('123456')

    const list = await screen.findByRole('list', { name: 'Recovery codes' })
    expect(within(list).getAllByRole('listitem')).toHaveLength(10)
    expect(screen.getByText('Two-step verification is on')).toBeInTheDocument()
    expect(screen.getByText(/They will not be shown again/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Copy' }))
    expect(writeText).toHaveBeenCalledWith(CODES.join('\n'))
    expect(await screen.findByText(/Copied/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Download' }))
    expect(createObjectURL).toHaveBeenCalled()
    expect(click).toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: 'I have saved them' }))
    expect(await screen.findByText('10 of 10')).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: 'Recovery codes' })).not.toBeInTheDocument()
    expect(api.fetchTwoStepStatus).toHaveBeenCalledTimes(2)
  })

  it('says so instead of offering a button when the server cannot protect a secret yet', async () => {
    api.fetchTwoStepStatus.mockResolvedValue({ ...OFF, available: false })

    render(<TwoStepSettings />)

    expect(await screen.findByText('Not available on this server yet')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Turn on two-step verification' })).not.toBeInTheDocument()
  })
})

describe('with two-step verification on', () => {
  it('turns it off only with a code, and says what that costs', async () => {
    api.fetchTwoStepStatus.mockResolvedValue(ON)
    api.disableTwoStep.mockResolvedValue(OFF)

    render(<TwoStepSettings />)

    await userEvent.click(await screen.findByRole('button', { name: 'Turn off' }))
    expect(screen.getByText(/you will not be able to connect to\s+your computers with nobody at them/)).toBeInTheDocument()

    const submit = screen.getByRole('button', { name: 'Turn off' })
    expect(submit).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Code from your authenticator app'), '246810')
    await userEvent.click(submit)

    expect(api.disableTwoStep).toHaveBeenCalledWith({ code: '246810' })
    expect(await screen.findByRole('button', { name: 'Turn on two-step verification' })).toBeInTheDocument()
  })

  it('can be turned off with a recovery code instead', async () => {
    api.fetchTwoStepStatus.mockResolvedValue(ON)
    api.disableTwoStep.mockResolvedValue(OFF)
    render(<TwoStepSettings />)

    await userEvent.click(await screen.findByRole('button', { name: 'Turn off' }))
    await userEvent.click(screen.getByRole('button', { name: 'Use a recovery code' }))
    await userEvent.type(screen.getByLabelText('Recovery code'), 'abcde23456')
    await userEvent.click(screen.getByRole('button', { name: 'Turn off' }))

    expect(api.disableTwoStep).toHaveBeenCalledWith({ recoveryCode: 'ABCDE23456' })
  })

  it('makes new recovery codes after saying the old ones stop working', async () => {
    api.fetchTwoStepStatus.mockResolvedValue({ ...ON, recoveryCodesLeft: 2 })
    api.regenerateRecoveryCodes.mockResolvedValue({ recoveryCodes: CODES })

    render(<TwoStepSettings />)

    expect(await screen.findByText('2 of 10')).toBeInTheDocument()
    expect(screen.getByText(/Only 2 recovery codes left/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'New recovery codes' }))
    expect(screen.getByRole('dialog', { name: 'Make new recovery codes' })).toHaveTextContent('the ones you have now stop working')
    await userEvent.click(screen.getByRole('button', { name: 'Make new codes' }))

    expect(api.regenerateRecoveryCodes).toHaveBeenCalled()
    expect(await screen.findByRole('list', { name: 'Recovery codes' })).toBeInTheDocument()
    expect(screen.queryByText('Two-step verification is on')).not.toBeInTheDocument()
  })
})

describe('the helpers', () => {
  it('groups the key in fours, as an app shows it', () => {
    expect(groupSecret('ABCDEFGHIJ')).toBe('ABCD EFGH IJ')
  })

  it('writes the recovery codes file with what they are and how to keep them', () => {
    const file = recoveryCodesFile(['AAAAA-22222'], new Date('2026-11-01T00:00:00Z'))
    expect(file).toContain('AICOUNTLY Remote — two-step verification recovery codes')
    expect(file).toContain('Made 2026-11-01')
    expect(file).toContain('AAAAA-22222')
  })
})
