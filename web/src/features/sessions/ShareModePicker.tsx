import { AppWindow, Globe, Monitor, ShieldCheck } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

import type { EffectivePolicy, ShareMode } from '../../types/remote'

/**
 * "How would you like to share?" (§14).
 *
 * AICOUNTLY Safe Share is first and recommended because it is the option that
 * exposes least: one AICOUNTLY tab, and nothing else the person has open.
 *
 * An option the organisation does not permit is shown **disabled with the
 * reason**, not hidden. Hiding it leaves the user wondering whether Remote can
 * do it at all; showing it says plainly that the organisation decided.
 */

interface Option {
  mode: ShareMode
  icon: LucideIcon
  title: string
  description: string
  recommended?: boolean
}

const OPTIONS: Option[] = [
  {
    mode: 'SAFE_SHARE',
    icon: ShieldCheck,
    title: 'AICOUNTLY Safe Share',
    description: 'Share your current AICOUNTLY workspace securely.',
    recommended: true,
  },
  {
    mode: 'BROWSER_TAB',
    icon: Globe,
    title: 'Browser tab',
    description: 'Share a single browser tab.',
  },
  {
    mode: 'APPLICATION_WINDOW',
    icon: AppWindow,
    title: 'Application window',
    description: 'Share one open application window.',
  },
  {
    mode: 'ENTIRE_MONITOR',
    icon: Monitor,
    title: 'Entire screen',
    description: 'Share everything on a display, including apps outside AICOUNTLY.',
  },
]

interface Props {
  policy: EffectivePolicy
  value: ShareMode
  onChange: (mode: ShareMode) => void
  /** False when the browser cannot capture at all — every option is inert. */
  captureAvailable?: boolean
}

export default function ShareModePicker({ policy, value, onChange, captureAvailable = true }: Props) {
  return (
    <fieldset className="share-picker">
      <legend className="share-picker__legend">How would you like to share?</legend>

      <div className="share-picker__options">
        {OPTIONS.map((option) => {
          const permitted = policy.allowedShareModes.includes(option.mode)
          const disabled = !permitted || !captureAvailable
          const selected = value === option.mode

          return (
            <label
              key={option.mode}
              className={[
                'share-option',
                selected ? 'share-option--selected' : '',
                disabled ? 'share-option--disabled' : '',
              ]
                .filter(Boolean)
                .join(' ')}
            >
              <input
                type="radio"
                name="shareMode"
                value={option.mode}
                checked={selected}
                disabled={disabled}
                onChange={() => onChange(option.mode)}
                className="share-option__input"
              />

              <span className="share-option__icon" aria-hidden="true">
                <option.icon size={20} />
              </span>

              <span className="share-option__body">
                <span className="share-option__title">
                  {option.title}
                  {option.recommended && permitted ? (
                    <span className="share-option__badge">Recommended</span>
                  ) : null}
                </span>

                <span className="share-option__description">
                  {disabled && !permitted
                    ? restrictionFor(option.mode, policy)
                    : option.description}
                </span>
              </span>
            </label>
          )
        })}
      </div>
    </fieldset>
  )
}

/**
 * Why this option is unavailable, in the organisation's own terms.
 *
 * The policy object distinguishes "the organisation switched this off" from
 * "you personally do not have this permission", and so should the wording —
 * they need different people to fix them.
 */
function restrictionFor(mode: ShareMode, policy: EffectivePolicy): string {
  const organisation = policy.companyName ?? 'Your organisation'

  const organisationAllows: Record<ShareMode, boolean> = {
    SAFE_SHARE: policy.allowSafeShare,
    BROWSER_TAB: policy.allowBrowserTab,
    APPLICATION_WINDOW: policy.allowApplicationWindow,
    ENTIRE_MONITOR: policy.allowEntireMonitor,
  }

  if (!organisationAllows[mode]) {
    return mode === 'ENTIRE_MONITOR'
      ? `${organisation} does not permit sharing an entire screen.`
      : `${organisation} does not permit this way of sharing.`
  }

  return 'Your account does not have permission for this. Ask your administrator.'
}
