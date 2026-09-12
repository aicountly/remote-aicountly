import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import ShareModePicker from './ShareModePicker'
import type { EffectivePolicy } from '../../types/remote'

/**
 * Policy-driven UI (§14, §39).
 *
 * Two properties are asserted, and they are the ones a screen-sharing product
 * has to get right in the interface as well as on the server:
 *
 *   * an option the organisation forbids is **disabled and explained**, not
 *     hidden — hiding it leaves the user wondering whether Remote can do it;
 *   * a disabled option cannot be selected, so it cannot reach the API at all.
 */

function policy(overrides: Partial<EffectivePolicy> = {}): EffectivePolicy {
  return {
    remoteEnabled: true,
    scopeType: 'COMPANY',
    companyId: 481,
    companyName: 'ABC Private Limited',
    policyPreset: 'STANDARD',
    allowSafeShare: true,
    allowBrowserTab: true,
    allowApplicationWindow: true,
    allowEntireMonitor: false,
    allowMicrophone: true,
    allowSystemAudio: false,
    allowTextChat: true,
    allowAnnotation: true,
    allowFileTransfer: false,
    allowExternalGuest: false,
    allowInternalSessions: true,
    allowAicountlySupport: true,
    allowRecording: false,
    // Desktop capabilities, all off — which is what a browser-only fixture
    // should be, and what every preset produces.
    allowRemoteControl: false,
    allowUnattendedAccess: false,
    allowClipboardSync: false,
    allowDeviceReboot: false,
    recordingRequiresConsent: true,
    maxSessionDurationMinutes: 60,
    guestLinkExpiryMinutes: 10,
    allowedShareModes: ['SAFE_SHARE', 'BROWSER_TAB', 'APPLICATION_WINDOW'],
    permissions: {},
    restrictions: [],
    ...overrides,
  }
}

describe('ShareModePicker', () => {
  it('recommends Safe Share', () => {
    render(<ShareModePicker policy={policy()} value="SAFE_SHARE" onChange={vi.fn()} />)

    expect(screen.getByText('AICOUNTLY Safe Share')).toBeInTheDocument()
    expect(screen.getByText('Recommended')).toBeInTheDocument()
  })

  it('disables a forbidden surface and names the organisation that forbade it', () => {
    render(<ShareModePicker policy={policy()} value="SAFE_SHARE" onChange={vi.fn()} />)

    const monitor = screen.getByRole('radio', { name: /Entire screen/i })

    expect(monitor).toBeDisabled()
    expect(
      screen.getByText('ABC Private Limited does not permit sharing an entire screen.'),
    ).toBeInTheDocument()
  })

  it('does not select a disabled option when it is clicked', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()

    render(<ShareModePicker policy={policy()} value="SAFE_SHARE" onChange={onChange} />)

    await user.click(screen.getByRole('radio', { name: /Entire screen/i }))

    expect(onChange).not.toHaveBeenCalled()
  })

  it('selects a permitted option', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()

    render(<ShareModePicker policy={policy()} value="SAFE_SHARE" onChange={onChange} />)

    await user.click(screen.getByRole('radio', { name: /Browser tab/i }))

    expect(onChange).toHaveBeenCalledWith('BROWSER_TAB')
  })

  it('distinguishes a personal permission gap from an organisation restriction', () => {
    // The organisation permits window sharing, but this user does not hold the
    // permission — so the fix is a different person, and the wording says so.
    render(
      <ShareModePicker
        policy={policy({
          // The organisation permits windows but not tabs, and the user has
          // neither in their effective modes — so the two disabled options must
          // give two different explanations.
          allowBrowserTab: false,
          allowApplicationWindow: true,
          allowedShareModes: ['SAFE_SHARE'],
        })}
        value="SAFE_SHARE"
        onChange={vi.fn()}
      />,
    )

    expect(
      screen.getByText('Your account does not have permission for this. Ask your administrator.'),
    ).toBeInTheDocument()
    expect(
      screen.getByText('ABC Private Limited does not permit this way of sharing.'),
    ).toBeInTheDocument()
  })

  it('disables every option when the browser cannot capture', () => {
    render(
      <ShareModePicker policy={policy()} value="SAFE_SHARE" onChange={vi.fn()} captureAvailable={false} />,
    )

    for (const option of screen.getAllByRole('radio')) {
      expect(option).toBeDisabled()
    }
  })
})
