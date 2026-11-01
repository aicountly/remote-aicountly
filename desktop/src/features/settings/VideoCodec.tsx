import { useState } from 'react'

import { StatusPill } from '../../components/StatusPill'
import * as bridge from '../../services/tauri'
import type { AgentConfig, CodecStatus, CodecView } from '../../types/agent'
import { OPENH264_ATTRIBUTION } from '../../types/agent'
import { useCodec } from './useCodec'

/**
 * Video codec: Cisco's OpenH264, which this computer's screen is encoded with.
 *
 * AICOUNTLY Remote ships no H.264 encoder of its own. It downloads Cisco's
 * binary from Cisco onto this computer, because Cisco's patent licence covers
 * that binary and only on conditions this section exists to meet
 * (docs/legal/OPENH264.md):
 *
 *   * the person can enable, disable and re-enable it — the switch, which
 *     takes effect at once rather than waiting for Save settings: off stops its
 *     use and deletes it, on downloads it again;
 *   * "OpenH264 Video Codec provided by Cisco Systems, Inc." is shown where it
 *     is controlled — it is the switch's own label, so the two cannot be
 *     separated.
 *
 * The licence text itself is in About, under Third-party licences.
 */
export function VideoCodecSettings({ onConfigChange }: { onConfigChange?: (config: AgentConfig) => void }) {
  const [codec, setCodec] = useCodec()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const act = async (action: () => Promise<CodecView>) => {
    setBusy(true)
    setError(null)

    try {
      setCodec(await action())
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : typeof caught === 'string' ? caught : 'Something went wrong.')

      // Whatever happened, show where it stands now rather than what was hoped.
      try {
        setCodec(await bridge.codecStatus())
      } catch {
        // The error above already says something is wrong.
      }
    } finally {
      setBusy(false)
    }
  }

  const toggle = (enabled: boolean) =>
    void act(async () => {
      const choice = await bridge.setCodecEnabled(enabled)
      onConfigChange?.(choice.config)

      return choice.codec
    })

  const download = () => void act(() => bridge.installCodec())

  return (
    <section className="card" aria-labelledby="video-codec-title">
      <h2 className="card__title" id="video-codec-title">
        Video codec
      </h2>
      <p className="card__subtitle">
        What this computer&rsquo;s screen is encoded with when you share it. Without it, the person helping you sees
        no picture.
      </p>

      {error ? (
        <div className="notice notice--danger" role="alert" style={{ marginBottom: 'var(--space-4)' }}>
          {error}
        </div>
      ) : null}

      {!codec ? (
        <p className="empty">Reading the codec&rsquo;s state&hellip;</p>
      ) : codec.status.state === 'unsupported' ? (
        <div className="notice notice--info">
          Cisco does not publish its OpenH264 codec for this computer ({codec.status.platform}), so this
          computer&rsquo;s screen cannot be shared from here.
        </div>
      ) : (
        <>
          <label className="field field--inline codec__switch">
            <span>
              <span className="field__label" id="openh264-attribution">
                {OPENH264_ATTRIBUTION}
              </span>
              <span className="field__hint" id="openh264-hint">
                Downloaded from Cisco onto this computer the first time it is needed, and used only to show your screen
                to the person you are sharing it with. Turning it off deletes it; turning it on downloads it again.
              </span>
            </span>
            <input
              type="checkbox"
              role="switch"
              aria-labelledby="openh264-attribution"
              aria-describedby="openh264-hint"
              checked={codec.enabled}
              onChange={(event) => toggle(event.target.checked)}
              disabled={busy}
            />
          </label>

          <CodecState status={codec.status} onDownload={download} busy={busy} />
        </>
      )}
    </section>
  )
}

function CodecState({
  status,
  onDownload,
  busy,
}: {
  status: CodecStatus
  onDownload: () => void
  busy: boolean
}) {
  switch (status.state) {
    case 'ready':
      return (
        <div className="card__rows" aria-live="polite">
          <div className="row">
            <span className="row__label">Status</span>
            <span className="row__value">
              <StatusPill tone="ready">Installed</StatusPill>
            </span>
          </div>
          <div className="row">
            <span className="row__label">Version</span>
            <span className="row__value row__value--mono">
              OpenH264 {status.version} · {formatBytes(status.sizeBytes)}
            </span>
          </div>
        </div>
      )

    case 'downloading': {
      const percent =
        status.totalBytes && status.totalBytes > 0
          ? Math.min(100, Math.round((status.receivedBytes / status.totalBytes) * 100))
          : null

      return (
        <div className="codec__progress" role="status">
          <p>
            Downloading from Cisco&hellip;
            {percent !== null ? ` ${percent}%` : ` ${formatBytes(status.receivedBytes)}`}
          </p>
          <progress
            max={status.totalBytes ?? undefined}
            value={status.totalBytes ? status.receivedBytes : undefined}
            aria-label="Download progress"
          />
        </div>
      )
    }

    case 'not_installed':
      return (
        <div className="codec__progress">
          <p>Not downloaded yet.</p>
          <button type="button" className="btn btn--secondary btn--small" onClick={onDownload} disabled={busy}>
            Download now
          </button>
        </div>
      )

    case 'failed':
      return (
        <div className="notice notice--danger" role="alert">
          <p className="notice__title">The codec could not be installed</p>
          <p>{status.reason}</p>
          <button type="button" className="btn btn--secondary btn--small" onClick={onDownload} disabled={busy}>
            Retry
          </button>
        </div>
      )

    case 'disabled':
      return (
        <div className="notice notice--info">
          Off, and not on this computer. While it is off, nobody you share this computer&rsquo;s screen with sees a
          picture.
        </div>
      )

    case 'unsupported':
      return null
  }
}

/** 1 234 567 bytes → "1.2 MB". */
export function formatBytes(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`

  return `${bytes} bytes`
}
