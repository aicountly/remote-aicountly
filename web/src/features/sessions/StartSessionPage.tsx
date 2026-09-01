import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowRight, Building2, Mic, ShieldCheck, User } from 'lucide-react'

import { useRemote } from '../../app/RemoteProvider'
import { createSession } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { describeCaptureLimitation } from '../../services/browser/capabilities'
import { PERMISSIONS } from '../../types/remote'
import type { ScopeType, ShareMode } from '../../types/remote'
import ShareModePicker from './ShareModePicker'
import RestrictionNotice from '../../components/ui/RestrictionNotice'

/**
 * Starting a session (§6A, §6B, §30).
 *
 * The organisation is chosen explicitly and shown throughout — never hidden,
 * never assumed. Where a launch context brought us here from another AICOUNTLY
 * product, the organisation is fixed and Personal is not offered at all, which
 * is the rule that stops a company workflow being escaped into a personal
 * session (§13).
 *
 * No capture happens on this page. It creates the session and hands off to the
 * room, where the consent step and the browser picker live.
 */
export default function StartSessionPage() {
  const { bootstrap, policy, scopeType, companyId, capabilities, switchScope, can } = useRemote()
  const navigate = useNavigate()

  const [shareMode, setShareMode] = useState<ShareMode>('SAFE_SHARE')
  const [useMicrophone, setUseMicrophone] = useState(false)
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState<RemoteApiError | null>(null)

  const companies = bootstrap?.companies ?? []
  const launchContext = bootstrap?.launchContext ?? null

  // A verified launch context locks the organisation. Offering "Personal" here
  // would be offering a way around that organisation's policy.
  const scopeLocked = Boolean(launchContext?.companyId)

  const captureLimitation = describeCaptureLimitation(capabilities)

  if (!policy) {
    return (
      <div className="page">
        <div className="skeleton" style={{ height: 420 }} />
      </div>
    )
  }

  if (!policy.remoteEnabled || !can(PERMISSIONS.SESSION_CREATE)) {
    return (
      <div className="page">
        <RestrictionNotice
          error={
            new RemoteApiError(
              policy.remoteEnabled ? 'SESSION_CREATE_DENIED' : 'COMPANY_REMOTE_DISABLED',
              policy.remoteEnabled
                ? 'You do not have permission to start a Remote session.'
                : `${policy.companyName ?? 'This organisation'} has turned off AICOUNTLY Remote.`,
              403,
            )
          }
          action={
            <button type="button" className="btn btn--secondary" onClick={() => navigate('/join')}>
              Join a session instead
            </button>
          }
        />
      </div>
    )
  }

  const allowedModes = policy.allowedShareModes
  const noModeAvailable = allowedModes.length === 0

  async function start() {
    setCreating(true)
    setError(null)

    try {
      const session = await createSession({
        scopeType,
        companyId,
        sessionType: scopeType === 'PERSONAL' ? 'ASSISTANCE' : 'INTERNAL',
        requestedShareMode: shareMode,
        allowAudio: useMicrophone,
      })

      navigate(`/room/${session.uuid}`, { state: { justCreated: true } })
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'The session could not be started.', 0),
      )
      setCreating(false)
    }
  }

  async function chooseScope(nextScope: ScopeType, nextCompanyId: number | null) {
    setError(null)
    await switchScope(nextScope, nextCompanyId)
    // The permitted sharing modes differ per organisation; the previous choice
    // may no longer be one of them.
    setShareMode('SAFE_SHARE')
  }

  return (
    <div className="page page--narrow">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Start a Remote session</h1>
          <p className="muted">
            You choose exactly what to share, and nothing is transmitted until you admit someone.
          </p>
        </header>

        {error ? <RestrictionNotice error={error} /> : null}

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">Session context</h2>
              <p className="card__subtitle">
                {scopeLocked
                  ? 'Set by the AICOUNTLY product you started Remote from.'
                  : 'Which organisation does this session belong to?'}
              </p>
            </div>
          </div>

          <div className="card__body">
            {scopeLocked ? (
              <div className="context-lock">
                <Building2 size={18} aria-hidden="true" />
                <div>
                  <p className="context-lock__name">{policy.companyName ?? `Company ${launchContext?.companyId}`}</p>
                  <p className="context-lock__hint">
                    Started from {launchContext?.product ? productLabel(launchContext.product) : 'AICOUNTLY'}
                    {launchContext?.route ? ` · ${launchContext.route}` : ''}. This session stays with this
                    organisation.
                  </p>
                </div>
              </div>
            ) : (
              <div className="scope-choices" role="radiogroup" aria-label="Session context">
                <ScopeChoice
                  icon={<User size={17} aria-hidden="true" />}
                  title="Personal"
                  hint="Not linked to an organisation"
                  selected={scopeType === 'PERSONAL'}
                  onSelect={() => void chooseScope('PERSONAL', null)}
                />

                {companies.map((company) => (
                  <ScopeChoice
                    key={company.companyId}
                    icon={<Building2 size={17} aria-hidden="true" />}
                    title={company.name}
                    hint={company.isCompanyAdmin ? 'Administrator' : 'Member'}
                    selected={scopeType !== 'PERSONAL' && companyId === company.companyId}
                    onSelect={() => void chooseScope('COMPANY', company.companyId)}
                  />
                ))}
              </div>
            )}
          </div>
        </section>

        <section className="card">
          <div className="card__body">
            {captureLimitation ? (
              <div className="notice notice--warning" role="status">
                <p className="notice__title">Screen sharing isn’t available in this browser</p>
                <p className="notice__body">{captureLimitation}</p>
              </div>
            ) : null}

            {noModeAvailable ? (
              <div className="notice notice--warning" role="status">
                <p className="notice__title">No sharing options are available</p>
                <p className="notice__body">
                  {policy.companyName ?? 'Your organisation'} has not permitted any way of sharing for your
                  account. You can still join a session and use chat.
                </p>
              </div>
            ) : (
              <ShareModePicker
                policy={policy}
                value={shareMode}
                onChange={setShareMode}
                captureAvailable={!captureLimitation}
              />
            )}

            {policy.allowMicrophone && can(PERMISSIONS.MICROPHONE_SHARE) ? (
              <label className="checkbox-row">
                <input
                  type="checkbox"
                  checked={useMicrophone}
                  onChange={(event) => setUseMicrophone(event.target.checked)}
                />
                <Mic size={15} aria-hidden="true" />
                <span>
                  Use my microphone
                  <span className="checkbox-row__hint">
                    Your browser will ask for permission when you turn it on in the session.
                  </span>
                </span>
              </label>
            ) : null}
          </div>

          <div className="card__footer">
            <div className="row row--between row--wrap">
              <p className="tiny muted row">
                <ShieldCheck size={14} aria-hidden="true" />
                Session activity is logged. Nothing on your screen is recorded.
              </p>

              <button
                type="button"
                className="btn btn--primary"
                onClick={() => void start()}
                disabled={creating || noModeAvailable}
              >
                {creating ? 'Creating session…' : 'Create session'}
                <ArrowRight size={16} aria-hidden="true" />
              </button>
            </div>
          </div>
        </section>
      </div>
    </div>
  )
}

function ScopeChoice({
  icon,
  title,
  hint,
  selected,
  onSelect,
}: {
  icon: React.ReactNode
  title: string
  hint: string
  selected: boolean
  onSelect: () => void
}) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      className={selected ? 'scope-choice scope-choice--selected' : 'scope-choice'}
      onClick={onSelect}
    >
      <span className="scope-choice__icon">{icon}</span>
      <span className="scope-choice__text">
        <span className="scope-choice__title truncate">{title}</span>
        <span className="scope-choice__hint">{hint}</span>
      </span>
    </button>
  )
}

function productLabel(code: string): string {
  const labels: Record<string, string> = {
    BOOKS: 'AICOUNTLY Books',
    HRMS: 'AICOUNTLY HRMS',
    AUDITOR: 'AICOUNTLY Auditor',
    INVENTORY: 'AICOUNTLY Inventory',
    ADVISOR: 'AICOUNTLY Advisor',
    MANAGE: 'AICOUNTLY Manage',
    PULSE: 'AICOUNTLY Pulse',
    CONNECT: 'AICOUNTLY Connect',
  }

  return labels[code.toUpperCase()] ?? code
}
