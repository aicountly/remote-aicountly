import type { HelpSession, HostedParticipant, HostedParticipants } from '../../types/agent'
import { ScreenRecordingNotice } from '../permissions/ScreenRecordingNotice'
import { formatSessionCode } from '../connect/ConnectScreen'

/**
 * Get help: the person at this computer gives somebody a code, lets them in,
 * and decides whether they may use the mouse and keyboard.
 *
 * Three separate yeses, all of them here: starting the session, admitting the
 * helper who typed the code, and granting control when the helper asks for it
 * (the control dialog every attended session has). The screen is shown from
 * the moment the helper is admitted — which this screen says before anybody
 * presses anything — and the banner at the top of the window shows that it is
 * for as long as it is.
 */
export function GetHelp({
  computerName,
  help,
  hosting,
  participants,
  canHost,
  needsScreenRecording,
  onOpenScreenRecording,
  onStart,
  onAnswer,
  onEnd,
  busy,
}: {
  computerName: string
  help: HelpSession | null
  /** The session is running on this computer, not just created. */
  hosting: boolean
  participants: HostedParticipants | null
  canHost: boolean
  needsScreenRecording: boolean
  onOpenScreenRecording: () => void
  onStart: () => void
  onAnswer: (participant: HostedParticipant, admit: boolean) => void
  onEnd: () => void
  busy?: boolean
}) {
  if (!canHost) {
    return (
      <section className="card">
        <h2 className="card__title">Get help</h2>
        <div className="notice notice--info">
          This version of AICOUNTLY Remote cannot show this {computerName}&rsquo;s screen. Update it from App settings,
          or start a session in <strong>Remote</strong> and share your screen from there.
        </div>
      </section>
    )
  }

  return (
    <section className="card help" aria-labelledby="get-help-title">
      <h2 className="card__title" id="get-help-title">
        Get help on this {computerName}
      </h2>

      {needsScreenRecording ? (
        <ScreenRecordingNotice computerName={computerName} onOpen={onOpenScreenRecording} busy={busy} />
      ) : null}

      {!help ? (
        <>
          <ol className="how-it-works__steps">
            <li>Get a code and read it to the person helping you.</li>
            <li>They type it in AICOUNTLY Remote. You let them in here, and they see your screen.</li>
            <li>If they ask to use your mouse and keyboard, you decide. Stop them at any time.</li>
          </ol>
          <div className="how-it-works__actions">
            <button type="button" className="btn btn--primary" onClick={onStart} disabled={busy}>
              Get a code
            </button>
          </div>
          <p className="field__hint">Only give the code to somebody you know and expected to help you.</p>
        </>
      ) : (
        <>
          <div className="help__code" aria-label="Your session code">
            <span className="help__code-label">Your code</span>
            <span className="help__code-value">
              {help.sessionCode ? formatSessionCode(help.sessionCode) : help.displayId}
            </span>
            <span className="field__hint">
              {hosting
                ? 'Read it to the person helping you. They type it under Join session, or Connect → Help someone.'
                : 'Starting the session on this computer…'}
            </span>
          </div>

          <ParticipantList
            title="Waiting to join"
            empty="Nobody yet."
            people={participants?.waiting ?? []}
            actions={(person) => (
              <>
                <button type="button" className="btn btn--primary btn--small" onClick={() => onAnswer(person, true)} disabled={busy}>
                  Let in
                </button>
                <button type="button" className="btn btn--secondary btn--small" onClick={() => onAnswer(person, false)} disabled={busy}>
                  Turn away
                </button>
              </>
            )}
          />

          <ParticipantList title="In the session" empty="Nobody else." people={participants?.joined ?? []} />

          <div className="how-it-works__actions">
            <button type="button" className="btn btn--danger" onClick={onEnd} disabled={busy}>
              End the session
            </button>
          </div>
        </>
      )}
    </section>
  )
}

function ParticipantList({
  title,
  empty,
  people,
  actions,
}: {
  title: string
  empty: string
  people: HostedParticipant[]
  actions?: (person: HostedParticipant) => React.ReactNode
}) {
  return (
    <div className="help__list">
      <h3 className="help__list-title">{title}</h3>
      {people.length === 0 ? (
        <p className="empty">{empty}</p>
      ) : (
        <ul className="computer-list">
          {people.map((person) => (
            <li className="computer" key={person.uuid}>
              <div className="computer__text">
                <p className="computer__name">{person.displayName || 'Someone'}</p>
              </div>
              {actions ? <div className="computer__actions">{actions(person)}</div> : null}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * Somebody typed this computer's code and is waiting: asked on every screen,
 * because it is a decision waiting on the person here and must not hide behind
 * the wrong tab.
 */
export function HelpAdmission({
  waiting,
  onAnswer,
  busy,
}: {
  waiting: HostedParticipant[]
  onAnswer: (participant: HostedParticipant, admit: boolean) => void
  busy?: boolean
}) {
  const first = waiting[0]
  if (!first) return null

  const name = first.displayName || 'Someone'

  return (
    <section className="notice notice--warning" role="alertdialog" aria-labelledby="help-admission-title">
      <p className="notice__title" id="help-admission-title">
        {name} wants to join your help session
      </p>
      <p>
        If you let them in they see your screen. They have to ask before they use your mouse and keyboard.
        {waiting.length > 1 ? ` ${waiting.length - 1} more waiting.` : ''}
      </p>
      <div className="session-banner__actions">
        <button type="button" className="btn btn--primary" onClick={() => onAnswer(first, true)} disabled={busy}>
          Let {name} in
        </button>
        <button type="button" className="btn btn--secondary" onClick={() => onAnswer(first, false)} disabled={busy}>
          Turn away
        </button>
      </div>
    </section>
  )
}
