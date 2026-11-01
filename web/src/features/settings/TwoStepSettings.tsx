import { useCallback, useEffect, useState } from 'react'
import { Copy, Download, KeyRound, ShieldCheck, ShieldOff } from 'lucide-react'

import {
  confirmTwoStep,
  disableTwoStep,
  fetchTwoStepStatus,
  regenerateRecoveryCodes,
  startTwoStepEnrolment,
} from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import QrCode from '../../components/ui/QrCode'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import Modal from '../../components/ui/Modal'
import TwoStepCodeForm from '../twoStep/TwoStepCodeForm'
import { formatDate } from '../../utils/format'
import type { TwoStepEnrolment, TwoStepStatus } from '../../types/remote'

/**
 * Settings → Two-step verification: Remote's own second step, on top of the
 * AICOUNTLY sign-in.
 *
 * Remote cannot see whether the portal asked for a second factor, so it asks
 * for its own: a code from an authenticator app whenever a new browser starts
 * using Remote, and again, fresh, before a computer can be reached with
 * nobody at it. This is where it is turned on — scan, confirm with a code,
 * save the ten recovery codes — and off, which takes a code too.
 *
 * Every step talks to the server and shows what the server says; nothing is
 * decided here. The secret and the recovery codes are shown once, from the
 * response that created them, and are not kept anywhere in the page after the
 * person moves on.
 */

type Phase =
  | { kind: 'status' }
  | { kind: 'enrolling'; enrolment: TwoStepEnrolment }
  | { kind: 'codes'; codes: string[]; first: boolean }
  | { kind: 'disabling' }

/** The secret as an app's "enter a key" field shows it: groups of four. */
export function groupSecret(secret: string): string {
  return secret.replace(/(.{4})/g, '$1 ').trim()
}

export function recoveryCodesFile(codes: string[], when: Date = new Date()): string {
  return [
    'AICOUNTLY Remote — two-step verification recovery codes',
    `Made ${when.toISOString().slice(0, 10)}. Each code works once, in place of a code from your authenticator app.`,
    'Keep them somewhere safe and away from your phone. Making new codes stops these working.',
    '',
    ...codes,
    '',
  ].join('\n')
}

export default function TwoStepSettings() {
  const [status, setStatus] = useState<TwoStepStatus | null>(null)
  const [phase, setPhase] = useState<Phase>({ kind: 'status' })
  const [error, setError] = useState<RemoteApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const [confirmNewCodes, setConfirmNewCodes] = useState(false)
  const [copied, setCopied] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      setStatus(await fetchTwoStepStatus())
      setError(null)
    } catch (err) {
      setError(err instanceof RemoteApiError ? err : new RemoteApiError('UNKNOWN', 'Two-step verification could not be loaded.', 0))
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  async function act(action: () => Promise<void>) {
    setBusy(true)
    setError(null)
    try {
      await action()
    } catch (err) {
      setError(err instanceof RemoteApiError ? err : new RemoteApiError('UNKNOWN', 'That could not be done.', 0))
    } finally {
      setBusy(false)
    }
  }

  async function copyCodes(codes: string[]) {
    try {
      await navigator.clipboard.writeText(codes.join('\n'))
      setCopied('Copied. Paste them somewhere safe.')
    } catch {
      setCopied('Your browser would not copy them. Select them and copy, or download them instead.')
    }
  }

  function downloadCodes(codes: string[]) {
    const url = URL.createObjectURL(new Blob([recoveryCodesFile(codes)], { type: 'text/plain;charset=utf-8' }))
    const link = document.createElement('a')
    link.href = url
    link.download = 'aicountly-remote-recovery-codes.txt'
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <section className="card" id="two-step" aria-labelledby="two-step-title">
      <div className="card__header">
        <div>
          <h2 className="card__title" id="two-step-title">
            Two-step verification
          </h2>
          <p className="card__subtitle">
            A code from an authenticator app on your phone, as well as your AICOUNTLY sign-in.
          </p>
        </div>
        {status?.enabled ? (
          <span className="badge badge--success">
            <ShieldCheck size={13} aria-hidden="true" /> On
          </span>
        ) : status ? (
          <span className="badge badge--neutral">Off</span>
        ) : null}
      </div>

      <div className="card__body stack">
        {error ? <RestrictionNotice error={error} compact /> : null}

        {status === null && !error ? <p className="muted">Loading…</p> : null}

        {status && phase.kind === 'status' ? (
          <StatusView
            status={status}
            busy={busy}
            onTurnOn={() =>
              void act(async () => {
                setPhase({ kind: 'enrolling', enrolment: await startTwoStepEnrolment() })
              })
            }
            onNewCodes={() => setConfirmNewCodes(true)}
            onTurnOff={() => setPhase({ kind: 'disabling' })}
          />
        ) : null}

        {phase.kind === 'enrolling' ? (
          <div className="stack two-step-enrol">
            <ol className="two-step-steps">
              <li>
                <p className="two-step-steps__title">Scan this code with your authenticator app</p>
                <p className="muted tiny">
                  Google Authenticator, Microsoft Authenticator, 1Password, Authy — any app that shows six-digit
                  codes. Add an account, and choose to scan a QR code.
                </p>
                <div className="two-step-qr">
                  <QrCode value={phase.enrolment.otpauthUri} label="QR code to add AICOUNTLY Remote to your authenticator app" />
                </div>
                <p className="tiny">
                  Cannot scan it? Choose to enter a key instead, and type:{' '}
                  <code className="two-step-secret" aria-label="Your setup key">
                    {groupSecret(phase.enrolment.secret)}
                  </code>
                </p>
              </li>
              <li>
                <p className="two-step-steps__title">Enter the code the app now shows</p>
                <TwoStepCodeForm
                  allowRecoveryCode={false}
                  submitLabel="Turn on"
                  onSubmit={async (proof) => {
                    if (!('code' in proof)) return
                    const confirmed = await confirmTwoStep(proof.code)
                    setPhase({ kind: 'codes', codes: confirmed.recoveryCodes, first: true })
                    setCopied(null)
                  }}
                  secondary={
                    <button type="button" className="btn btn--secondary" onClick={() => setPhase({ kind: 'status' })}>
                      Cancel
                    </button>
                  }
                />
              </li>
            </ol>
          </div>
        ) : null}

        {phase.kind === 'codes' ? (
          <div className="stack two-step-codes">
            {phase.first ? (
              <div className="notice notice--info" role="status">
                <p className="notice__title">Two-step verification is on</p>
                <p className="notice__body">
                  From now on a new browser asks for a code from your app before it can use Remote.
                </p>
              </div>
            ) : null}
            <div>
              <p className="two-step-steps__title">Save your recovery codes</p>
              <p className="muted tiny">
                If you lose your phone, each of these works once in place of a code from your app. Keep them somewhere
                safe and away from your phone. They will not be shown again.
              </p>
            </div>
            <ol className="recovery-codes" aria-label="Recovery codes">
              {phase.codes.map((code) => (
                <li key={code}>
                  <code>{code}</code>
                </li>
              ))}
            </ol>
            <div className="row row--wrap">
              <button type="button" className="btn btn--secondary btn--sm" onClick={() => void copyCodes(phase.codes)}>
                <Copy size={14} aria-hidden="true" /> Copy
              </button>
              <button type="button" className="btn btn--secondary btn--sm" onClick={() => downloadCodes(phase.codes)}>
                <Download size={14} aria-hidden="true" /> Download
              </button>
            </div>
            {copied ? (
              <p className="tiny muted" role="status">
                {copied}
              </p>
            ) : null}
            <div>
              <button
                type="button"
                className="btn btn--primary"
                onClick={() => {
                  setPhase({ kind: 'status' })
                  setCopied(null)
                  void load()
                }}
              >
                I have saved them
              </button>
            </div>
          </div>
        ) : null}

        {phase.kind === 'disabling' ? (
          <div className="stack">
            <div className="notice notice--warning">
              <p className="notice__title">Turning two-step verification off</p>
              <p className="notice__body">
                Your AICOUNTLY sign-in alone will be enough to use Remote again, and you will not be able to connect to
                your computers with nobody at them until you turn it back on.
              </p>
            </div>
            <TwoStepCodeForm
              submitLabel="Turn off"
              onSubmit={async (proof) => {
                setStatus(await disableTwoStep(proof))
                setPhase({ kind: 'status' })
              }}
              secondary={
                <button type="button" className="btn btn--secondary" onClick={() => setPhase({ kind: 'status' })}>
                  Cancel
                </button>
              }
            />
          </div>
        ) : null}
      </div>

      <Modal
        open={confirmNewCodes}
        title="Make new recovery codes"
        size="sm"
        onClose={() => setConfirmNewCodes(false)}
        footer={
          <>
            <button type="button" className="btn btn--secondary" onClick={() => setConfirmNewCodes(false)}>
              Cancel
            </button>
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => {
                setConfirmNewCodes(false)
                void act(async () => {
                  const { recoveryCodes } = await regenerateRecoveryCodes()
                  setPhase({ kind: 'codes', codes: recoveryCodes, first: false })
                  setCopied(null)
                })
              }}
            >
              Make new codes
            </button>
          </>
        }
      >
        <p>
          You get ten new codes, and the ones you have now stop working. If you entered a code from your app more than a
          few minutes ago, you will be asked for another first.
        </p>
      </Modal>
    </section>
  )
}

function StatusView({
  status,
  busy,
  onTurnOn,
  onNewCodes,
  onTurnOff,
}: {
  status: TwoStepStatus
  busy: boolean
  onTurnOn: () => void
  onNewCodes: () => void
  onTurnOff: () => void
}) {
  if (!status.enabled) {
    return (
      <div className="stack">
        <p>
          With two-step verification on, a browser that starts using AICOUNTLY Remote must enter a code from your
          authenticator app, even when somebody has your AICOUNTLY password. It is also what lets you connect to your own
          computers with nobody at them: unattended access needs it.
        </p>
        {status.available ? (
          <div>
            <button type="button" className="btn btn--primary" onClick={onTurnOn} disabled={busy}>
              <KeyRound size={15} aria-hidden="true" /> Turn on two-step verification
            </button>
          </div>
        ) : (
          <div className="notice notice--warning" role="status">
            <p className="notice__title">Not available on this server yet</p>
            <p className="notice__body">
              Two-step verification cannot be turned on until this AICOUNTLY Remote server is set up for it. Please tell
              AICOUNTLY support.
            </p>
          </div>
        )}
      </div>
    )
  }

  const few = status.recoveryCodesLeft <= 3

  return (
    <div className="stack">
      <dl className="detail-list">
        <div className="detail-list__row">
          <dt>Status</dt>
          <dd>On{status.confirmedAt ? ` since ${formatDate(status.confirmedAt)}` : ''}</dd>
        </div>
        <div className="detail-list__row">
          <dt>Recovery codes left</dt>
          <dd>{status.recoveryCodesLeft} of 10</dd>
        </div>
        <div className="detail-list__row">
          <dt>This browser</dt>
          <dd>{status.sessionVerified ? 'Verified' : 'Not verified yet'}</dd>
        </div>
      </dl>

      {few ? (
        <div className="notice notice--warning" role="status">
          <p className="notice__body">
            {status.recoveryCodesLeft === 0
              ? 'You have no recovery codes left. Make new ones, so losing your phone does not lock you out.'
              : `Only ${status.recoveryCodesLeft} recovery code${status.recoveryCodesLeft === 1 ? '' : 's'} left. Make new ones before you run out.`}
          </p>
        </div>
      ) : null}

      <div className="row row--wrap">
        <button type="button" className="btn btn--secondary" onClick={onNewCodes} disabled={busy}>
          <KeyRound size={15} aria-hidden="true" /> New recovery codes
        </button>
        <button type="button" className="btn btn--ghost" onClick={onTurnOff} disabled={busy}>
          <ShieldOff size={15} aria-hidden="true" /> Turn off
        </button>
      </div>
    </div>
  )
}
