import { useState } from 'react'

import type { OwnComputer } from '../../types/agent'
import { YourComputers } from '../computers/YourComputers'

/** The nine digits of a session code, from whatever was typed or pasted. */
export function sessionCodeDigits(typed: string): string {
  return typed.replace(/\D/g, '').slice(0, 9)
}

/** `123456789` → `123 456 789`. */
export function formatSessionCode(code: string): string {
  return code.replace(/\D/g, '').replace(/(\d{3})(?=\d)/g, '$1 ')
}

/**
 * Connect: to one of your own computers with nobody at it, or to somebody who
 * has read you their code.
 *
 * Both open in Remote inside this window, where the picture, the controls and
 * the chat are the web room's — so connecting from here and from a browser
 * are the same thing, with the same rules.
 */
export function ConnectScreen({
  onConnect,
  onJoin,
  onManage,
}: {
  onConnect: (computer: OwnComputer) => void
  onJoin: (code: string) => void
  onManage: () => void
}) {
  return (
    <>
      <YourComputers onConnect={onConnect} onManage={onManage} />
      <HelpSomeone onJoin={onJoin} />
    </>
  )
}

/** Join somebody else's session by the code they read out. */
export function HelpSomeone({ onJoin }: { onJoin: (code: string) => void }) {
  const [code, setCode] = useState('')
  const digits = sessionCodeDigits(code)
  const ready = digits.length === 9

  return (
    <section className="card" aria-labelledby="help-someone-title">
      <h2 className="card__title" id="help-someone-title">
        Help someone
      </h2>
      <p className="card__subtitle">
        Ask them to open AICOUNTLY Remote and choose <strong>Get help</strong>, then type the code they read out.
      </p>

      <form
        className="join-code"
        onSubmit={(event) => {
          event.preventDefault()
          if (ready) onJoin(digits)
        }}
      >
        <label className="field">
          <span className="field__label">Session code</span>
          <input
            className="join-code__input"
            inputMode="numeric"
            autoComplete="off"
            placeholder="123 456 789"
            value={formatSessionCode(digits)}
            onChange={(event) => setCode(event.target.value)}
            aria-describedby="help-someone-hint"
          />
        </label>
        <button type="submit" className="btn btn--primary" disabled={!ready}>
          Join
        </button>
      </form>
      <p className="field__hint" id="help-someone-hint">
        They let you in, and you ask before you use their mouse and keyboard. They can stop you at any time.
      </p>
    </section>
  )
}
