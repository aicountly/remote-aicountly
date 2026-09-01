import { useCallback, useEffect, useState } from 'react'
import { Check, Loader2 } from 'lucide-react'

import { fetchCompanyPolicy, updateCompanyPolicy } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { useRemote } from '../../app/RemoteProvider'
import Toggle from '../../components/ui/Toggle'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import EmptyState from '../../components/ui/EmptyState'
import type { CompanyPolicy, Entitlement } from '../../types/remote'
import { PERMISSIONS } from '../../types/remote'

/**
 * Remote Access Policy (§40).
 *
 * Presets on top, then the individual switches grouped as the specification
 * lays them out. Changing any switch moves the record to Custom — the server
 * decides that, and the label here reflects what came back rather than what the
 * page assumed.
 *
 * Two things are deliberate:
 *   * **entire-screen sharing carries its warning inline**, not in a tooltip,
 *     because it is the one switch that can expose applications nobody meant to
 *     share;
 *   * **a switch the plan does not include is disabled with the reason**, so an
 *     administrator is never left toggling something that will not take effect.
 */

const PRESET_DESCRIPTIONS: Record<string, string> = {
  RESTRICTED: 'Remote is switched off for everyone in this organisation.',
  SAFE: 'AICOUNTLY-assisted support only, using Safe Share. Staff cannot start sessions between themselves.',
  STANDARD: 'Normal company-approved browser assistance. Entire-screen sharing stays off.',
  OPEN: 'Wider sharing, including entire screens and external guests.',
  CUSTOM: 'Settings chosen by an administrator.',
}

export default function PolicyPage() {
  const { companyId, scopeType, can, policy: effective, refresh } = useRemote()

  const [policy, setPolicy] = useState<CompanyPolicy | null>(null)
  const [entitlement, setEntitlement] = useState<Entitlement | null>(null)
  const [companyName, setCompanyName] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [savedAt, setSavedAt] = useState<number | null>(null)
  const [error, setError] = useState<RemoteApiError | null>(null)

  const load = useCallback(async () => {
    if (companyId === null) {
      setLoading(false)

      return
    }

    setLoading(true)

    try {
      const result = await fetchCompanyPolicy(companyId)
      setPolicy(result.policy)
      setEntitlement(result.entitlement)
      setCompanyName(result.companyName)
      setError(null)
    } catch (err) {
      setError(
        err instanceof RemoteApiError ? err : new RemoteApiError('UNKNOWN', 'The policy could not be loaded.', 0),
      )
    } finally {
      setLoading(false)
    }
  }, [companyId])

  useEffect(() => {
    void load()
  }, [load])

  if (scopeType === 'PERSONAL' || companyId === null) {
    return (
      <div className="page">
        <EmptyState
          title="Choose an organisation"
          description="Remote policy belongs to an organisation. Switch from Personal using the selector at the top of the page."
        />
      </div>
    )
  }

  if (!can(PERMISSIONS.POLICY_VIEW)) {
    return (
      <div className="page">
        <RestrictionNotice
          error={
            new RemoteApiError(
              'ADMIN_PERMISSION_DENIED',
              'You do not have permission to view Remote policy for this organisation.',
              403,
            )
          }
        />
      </div>
    )
  }

  if (loading || !policy) {
    return (
      <div className="page" aria-busy="true">
        <div className="skeleton" style={{ height: 480 }} />
      </div>
    )
  }

  const readOnly = !can(PERMISSIONS.POLICY_MANAGE)

  async function save(changes: Partial<CompanyPolicy> & { preset?: string }) {
    if (companyId === null || readOnly) return

    setSaving(true)
    setError(null)

    // Optimistic, because a toggle that lags feels broken — reconciled with the
    // server's answer below, which is authoritative.
    setPolicy((current) => (current ? { ...current, ...changes } as CompanyPolicy : current))

    try {
      const result = await updateCompanyPolicy(companyId, changes)
      setPolicy(result.policy)
      setSavedAt(Date.now())

      // The switch just changed may have changed what *this* administrator can
      // do, so the app-wide effective policy is refetched too.
      await refresh()
    } catch (err) {
      setError(err instanceof RemoteApiError ? err : null)
      await load()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="page">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Remote access policy</h1>
          <p className="muted">
            Control how {companyName ?? effective?.companyName ?? 'this organisation'}’s users can use
            AICOUNTLY Remote.
          </p>
        </header>

        {error ? <RestrictionNotice error={error} /> : null}

        {readOnly ? (
          <div className="notice notice--info">
            <p className="notice__title">You can view this policy but not change it</p>
            <p className="notice__body">Ask a company administrator to make changes.</p>
          </div>
        ) : null}

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">Policy preset</h2>
              <p className="card__subtitle">{PRESET_DESCRIPTIONS[policy.policyPreset]}</p>
            </div>

            {saving ? (
              <span className="save-state" role="status">
                <Loader2 size={14} className="spin" aria-hidden="true" />
                Saving…
              </span>
            ) : savedAt ? (
              <span className="save-state save-state--saved" role="status">
                <Check size={14} aria-hidden="true" />
                Saved
              </span>
            ) : null}
          </div>

          <div className="card__body preset-row" role="radiogroup" aria-label="Policy preset">
            {(['RESTRICTED', 'SAFE', 'STANDARD', 'OPEN', 'CUSTOM'] as const).map((preset) => (
              <button
                key={preset}
                type="button"
                role="radio"
                aria-checked={policy.policyPreset === preset}
                className={policy.policyPreset === preset ? 'preset preset--selected' : 'preset'}
                disabled={readOnly || preset === 'CUSTOM'}
                onClick={() => void save({ preset })}
              >
                {preset.charAt(0) + preset.slice(1).toLowerCase()}
              </button>
            ))}
          </div>
        </section>

        <PolicySection title="Remote availability">
          <Toggle
            label="Remote assistance"
            checked={policy.remoteEnabled}
            disabled={readOnly}
            explanation="When this is off, nobody in this organisation can start or join a Remote session."
            onChange={(checked) => void save({ remoteEnabled: checked })}
          />
        </PolicySection>

        <PolicySection title="Screen sharing">
          <Toggle
            label="AICOUNTLY Safe Share"
            checked={policy.allowSafeShare}
            disabled={readOnly || !policy.remoteEnabled}
            explanation="Share an AICOUNTLY workspace tab. The recommended option — it exposes the least."
            onChange={(checked) => void save({ allowSafeShare: checked })}
          />
          <Toggle
            label="Browser tabs"
            checked={policy.allowBrowserTab}
            disabled={readOnly || !policy.remoteEnabled}
            explanation="Share any single browser tab."
            onChange={(checked) => void save({ allowBrowserTab: checked })}
          />
          <Toggle
            label="Application windows"
            checked={policy.allowApplicationWindow}
            disabled={readOnly || !policy.remoteEnabled}
            explanation="Share one open application window."
            onChange={(checked) => void save({ allowApplicationWindow: checked })}
          />
          <Toggle
            label="Entire screen"
            checked={policy.allowEntireMonitor}
            disabled={readOnly || !policy.remoteEnabled}
            explanation="Entire-screen sharing may expose applications outside AICOUNTLY. Keep this disabled unless your organisation specifically requires it."
            onChange={(checked) => void save({ allowEntireMonitor: checked })}
          />
        </PolicySection>

        <PolicySection title="Collaboration">
          <Toggle
            label="Microphone"
            checked={policy.allowMicrophone}
            disabled={readOnly || !policy.remoteEnabled}
            explanation="Participants may turn on their microphone. They are always asked by the browser first."
            onChange={(checked) => void save({ allowMicrophone: checked })}
          />
          <Toggle
            label="System audio"
            checked={policy.allowSystemAudio}
            disabled={readOnly || !policy.remoteEnabled}
            explanation="Share sound played by the shared tab or screen. Not supported in every browser."
            onChange={(checked) => void save({ allowSystemAudio: checked })}
          />
          <Toggle
            label="Chat"
            checked={policy.allowTextChat}
            disabled={readOnly || !policy.remoteEnabled}
            explanation="Messages are kept with the session record. They are not written to the audit trail."
            onChange={(checked) => void save({ allowTextChat: checked })}
          />
          <Toggle
            label="Annotations"
            checked={policy.allowAnnotation}
            disabled={readOnly || !policy.remoteEnabled}
            explanation="Drawing on the shared screen. Annotations are overlays and never change the shared application."
            onChange={(checked) => void save({ allowAnnotation: checked })}
          />
          <Toggle
            label="File transfer"
            checked={policy.allowFileTransfer}
            disabled={readOnly || !policy.remoteEnabled || !entitlement?.fileTransfer}
            disabledReason={
              entitlement && !entitlement.fileTransfer
                ? `File transfer is not included in the ${entitlement.planCode.replace(/_/g, ' ').toLowerCase()} plan.`
                : undefined
            }
            explanation="Files move directly between the two browsers and are never stored by AICOUNTLY."
            onChange={(checked) => void save({ allowFileTransfer: checked })}
          />
        </PolicySection>

        <PolicySection title="Participants">
          <Toggle
            label="Internal company users"
            checked={policy.allowInternalSessions}
            disabled={readOnly || !policy.remoteEnabled}
            explanation="Colleagues in this organisation may assist each other."
            onChange={(checked) => void save({ allowInternalSessions: checked })}
          />
          <Toggle
            label="AICOUNTLY Support"
            checked={policy.allowAicountlySupport}
            disabled={readOnly || !policy.remoteEnabled}
            explanation="Your users can ask AICOUNTLY to look at their screen. They still approve each technician."
            onChange={(checked) => void save({ allowAicountlySupport: checked })}
          />
          <Toggle
            label="External guests"
            checked={policy.allowExternalGuest}
            disabled={readOnly || !policy.remoteEnabled || !entitlement?.externalGuests}
            disabledReason={
              entitlement && !entitlement.externalGuests
                ? `External guests are not included in the ${entitlement.planCode.replace(/_/g, ' ').toLowerCase()} plan.`
                : undefined
            }
            explanation="People without an AICOUNTLY account can join by one-time link. They see only the shared screen."
            onChange={(checked) => void save({ allowExternalGuest: checked })}
          />
        </PolicySection>

        <PolicySection title="Recording">
          <Toggle
            label="Session recording"
            checked={policy.allowRecording}
            disabled={readOnly || !policy.remoteEnabled || !entitlement?.recording}
            disabledReason={
              entitlement && !entitlement.recording
                ? 'Recording is not available on this plan.'
                : undefined
            }
            explanation="Off by default. Nothing is recorded unless this is enabled and every participant consents."
            onChange={(checked) => void save({ allowRecording: checked })}
          />
          <Toggle
            label="Require participant consent"
            checked={policy.recordingRequiresConsent}
            disabled={readOnly || !policy.allowRecording}
            explanation="Everyone in the session must agree before a recording can begin."
            onChange={(checked) => void save({ recordingRequiresConsent: checked })}
          />
        </PolicySection>

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">Security</h2>
              <p className="card__subtitle">Limits that apply to every Remote session in this organisation.</p>
            </div>
          </div>

          <div className="card__body stack">
            <div className="field">
              <label className="field__label" htmlFor="max-duration">
                Maximum session duration
              </label>
              <div className="row">
                <input
                  id="max-duration"
                  type="number"
                  className="input input--narrow"
                  min={5}
                  max={1440}
                  value={policy.maxSessionDurationMinutes}
                  disabled={readOnly}
                  onChange={(event) =>
                    setPolicy((current) =>
                      current ? { ...current, maxSessionDurationMinutes: Number(event.target.value) } : current,
                    )
                  }
                  onBlur={(event) => void save({ maxSessionDurationMinutes: Number(event.target.value) })}
                />
                <span className="muted">minutes</span>
              </div>
              <p className="field__hint">
                A session ends automatically after this long, even if nobody closes it.
                {entitlement?.maxSessionDurationMinutes
                  ? ` Your plan caps this at ${entitlement.maxSessionDurationMinutes} minutes.`
                  : ''}
              </p>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="invite-expiry">
                Guest invitation expiry
              </label>
              <div className="row">
                <input
                  id="invite-expiry"
                  type="number"
                  className="input input--narrow"
                  min={1}
                  max={1440}
                  value={policy.guestLinkExpiryMinutes}
                  disabled={readOnly}
                  onChange={(event) =>
                    setPolicy((current) =>
                      current ? { ...current, guestLinkExpiryMinutes: Number(event.target.value) } : current,
                    )
                  }
                  onBlur={(event) => void save({ guestLinkExpiryMinutes: Number(event.target.value) })}
                />
                <span className="muted">minutes</span>
              </div>
              <p className="field__hint">
                Invitation links stop working after this long. Shorter is safer — a link that is still live is a
                way into a session.
              </p>
            </div>
          </div>
        </section>
      </div>
    </div>
  )
}

function PolicySection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="card">
      <div className="card__header">
        <h2 className="card__title">{title}</h2>
      </div>
      <div className="card__body stack">{children}</div>
    </section>
  )
}
