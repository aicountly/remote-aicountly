import { useEffect, useState } from 'react'
import { Check, Copy } from 'lucide-react'

interface Props {
  code: string
}

/**
 * The code a person types on the Join page, shown where the host looks first (§6E).
 *
 * It used to live only in the Details panel, so a host reading it out to somebody had to find it first. It is not a
 * credential on its own — joining still needs the host to admit the person — and it disappears the moment the session
 * ends, because the server stops returning it.
 */
export default function SessionCodeBadge({ code }: Props) {
  const [copied, setCopied] = useState(false)

  useEffect(() => {
    if (!copied) return

    const timer = setTimeout(() => setCopied(false), 2000)

    return () => clearTimeout(timer)
  }, [copied])

  async function copy() {
    try {
      await navigator.clipboard.writeText(code)
      setCopied(true)
    } catch {
      // Clipboard access can be refused. The code is on screen and selectable.
      setCopied(false)
    }
  }

  return (
    <span className="room__code">
      <span className="room__code-label">Session code</span>
      <span className="room__code-value mono" aria-label={`Session code ${code.split('').join(' ')}`}>
        {code}
      </span>
      <button
        type="button"
        className="room__code-copy"
        onClick={() => void copy()}
        aria-label={copied ? 'Session code copied' : 'Copy session code'}
        title={copied ? 'Copied' : 'Copy session code'}
      >
        {copied ? <Check size={13} aria-hidden="true" /> : <Copy size={13} aria-hidden="true" />}
      </button>
      <span className="sr-only" role="status">
        {copied ? 'Session code copied' : ''}
      </span>
    </span>
  )
}
