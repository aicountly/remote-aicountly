import type { CodecView } from '../../types/agent'
import { OPENH264_ATTRIBUTION } from '../../types/agent'

/**
 * No picture: this computer has no H.264 codec to encode its screen with.
 *
 * AICOUNTLY Remote ships no encoder of its own; it uses Cisco's OpenH264,
 * downloaded from Cisco onto this computer, which the person here can switch
 * off. Without it a session still connects — chat, control and the rest work
 * — but the person helping sees nothing, and the agent does not pretend
 * otherwise: the session reports every frame it could not send, and this says
 * so to the person at the computer, naming the setting that fixes it.
 *
 * `session` is a session that is running; `help` is the Get help screen,
 * before anybody has been given a code.
 */
export function CodecNotice({
  codec,
  context,
  onOpenSettings,
}: {
  codec: CodecView
  context: 'session' | 'help'
  onOpenSettings?: () => void
}) {
  const problem = describe(codec)

  if (!problem) return null

  return (
    <section
      className="notice notice--warning"
      role={context === 'session' ? 'alert' : 'status'}
      aria-labelledby={`codec-notice-${context}`}
    >
      <p className="notice__title" id={`codec-notice-${context}`}>
        {context === 'session'
          ? 'The person connected to this computer cannot see your screen'
          : 'The person helping you would not see your screen'}
      </p>
      <p>
        {problem.what}{' '}
        {problem.fix === 'turn_on' ? (
          <>
            Turn on <strong>{OPENH264_ATTRIBUTION}</strong> in App settings, under Video codec.
          </>
        ) : problem.fix === 'retry' ? (
          <>Retry it in App settings, under Video codec.</>
        ) : problem.fix === 'download' ? (
          <>Download it in App settings, under Video codec.</>
        ) : null}
      </p>
      {problem.fix !== 'wait' && problem.fix !== 'none' && onOpenSettings ? (
        <div className="session-banner__actions">
          <button type="button" className="btn btn--secondary btn--small" onClick={onOpenSettings}>
            Open App settings
          </button>
        </div>
      ) : null}
    </section>
  )
}

type Fix = 'turn_on' | 'retry' | 'download' | 'wait' | 'none'

/** What is wrong, in a sentence, and what the person can do about it. */
function describe(codec: CodecView): { what: string; fix: Fix } | null {
  const status = codec.status

  switch (status.state) {
    case 'ready':
      return null
    case 'disabled':
      return {
        what: 'The video codec this computer’s screen is encoded with is switched off.',
        fix: 'turn_on',
      }
    case 'not_installed':
      return { what: 'The video codec this computer’s screen is encoded with is not on this computer.', fix: 'download' }
    case 'downloading':
      return {
        what: 'The video codec is still being downloaded from Cisco. The picture starts as soon as it is ready.',
        fix: 'wait',
      }
    case 'failed':
      return { what: `The video codec could not be downloaded: ${status.reason}`, fix: 'retry' }
    case 'unsupported':
      return {
        what: 'Cisco does not publish the video codec for this computer, so its screen cannot be shown from here.',
        fix: 'none',
      }
  }
}
