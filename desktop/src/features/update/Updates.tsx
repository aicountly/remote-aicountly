import { useCallback, useEffect, useState } from 'react'

import * as bridge from '../../services/tauri'
import type { UpdateInfo, UpdateStarted } from '../../types/agent'

/**
 * Telling somebody a newer AICOUNTLY Remote is published, and installing it
 * if they say so.
 *
 * Checked once, when the window opens (`check_for_update`, which reads the
 * same manifest the website's download page does). Then:
 *
 *   * **a prompt**, Install update or Skip. Skip is a real answer: the
 *     application carries on exactly as it was, and nothing is asked again
 *     until it is next opened;
 *   * **a banner** for as long as this version is out of date, prompt
 *     answered or not, with Update now and the download page — so installing
 *     later never means waiting for the next launch.
 *
 * A check that failed — no network, the host down, a manifest this build
 * cannot read — is indistinguishable here from "up to date": nothing is
 * shown, and nothing else in the window waits on it.
 *
 * The prompt is not shown over a running session. It would sit on top of a
 * request to control this computer, which is a decision that must never be
 * hidden; the banner says the same thing without being in the way, and
 * installing is refused until the session ends anyway.
 */
export function Updates({
  sessionActive,
  onPromptChange,
}: {
  sessionActive: boolean
  /**
   * Whether the prompt is on screen. The web portal is drawn over this
   * window's page, so it has to step aside while the prompt is showing.
   */
  onPromptChange?: (showing: boolean) => void
}) {
  const [update, setUpdate] = useState<UpdateInfo | null>(null)
  const [prompting, setPrompting] = useState(false)
  const [installing, setInstalling] = useState(false)
  const [started, setStarted] = useState<UpdateStarted | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    void bridge.checkForUpdate().then((found) => {
      if (cancelled || !found) return

      setUpdate(found)
      setPrompting(true)
    })

    return () => {
      cancelled = true
    }
  }, [])

  const openDownloadPage = useCallback(() => {
    if (!update) return

    void bridge.openUrl(update.downloadPageUrl).catch(() => undefined)
  }, [update])

  const install = useCallback(async () => {
    if (!update) return

    setError(null)

    // A build for this platform but not this architecture: there is nothing
    // this application may fetch for itself, so the page is where it goes.
    if (!update.installer) {
      openDownloadPage()
      setPrompting(false)
      return
    }

    setInstalling(true)

    try {
      setStarted(await bridge.installUpdate())
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : typeof caught === 'string'
            ? caught
            : 'The update could not be installed.',
      )
    } finally {
      setInstalling(false)
    }
  }, [update, openDownloadPage])

  const skip = useCallback(() => {
    setPrompting(false)
    setError(null)
  }, [])

  const showingPrompt = update !== null && prompting && !sessionActive

  useEffect(() => {
    onPromptChange?.(showingPrompt)
  }, [showingPrompt, onPromptChange])

  if (!update) return null

  if (showingPrompt) {
    return (
      <div className="update-dialog__backdrop">
        <section
          className="card update-dialog"
          role="dialog"
          aria-modal="true"
          aria-labelledby="update-dialog-title"
        >
          <h2 className="card__title" id="update-dialog-title">
            Update available
          </h2>
          <p className="card__subtitle">
            AICOUNTLY Remote {versionLabel(update.latestVersion, update.latestBuild)} is available. You have{' '}
            {versionLabel(update.currentVersion, update.currentBuild)}.
          </p>

          <UpdateProgress
            update={update}
            installing={installing}
            started={started}
            error={error}
            onDownloadPage={openDownloadPage}
          />

          {started ? null : (
            <div className="update-dialog__actions">
              <button type="button" className="btn btn--primary" onClick={() => void install()} disabled={installing}>
                {installing ? 'Downloading…' : 'Install update'}
              </button>
              <button type="button" className="btn btn--secondary" onClick={skip} disabled={installing}>
                Skip
              </button>
            </div>
          )}
        </section>
      </div>
    )
  }

  return (
    <div className="notice notice--info update-banner" role="status">
      <div className="update-banner__text">
        <p className="notice__title">A new version of AICOUNTLY Remote is available</p>
        <p>
          Version {versionLabel(update.latestVersion, update.latestBuild)} is ready to install. You have{' '}
          {versionLabel(update.currentVersion, update.currentBuild)}.
        </p>
        <UpdateProgress
          update={update}
          installing={installing}
          started={started}
          error={error}
          onDownloadPage={openDownloadPage}
        />
      </div>

      {started ? null : (
        <div className="update-banner__actions">
          <button type="button" className="btn btn--primary" onClick={() => void install()} disabled={installing}>
            {installing ? 'Downloading…' : 'Update now'}
          </button>
          <button type="button" className="btn btn--secondary" onClick={openDownloadPage} disabled={installing}>
            Download page
          </button>
        </div>
      )}
    </div>
  )
}

/**
 * `1.0.0 (build 202610301030)`. Several builds go out as one version, so the
 * version alone would read "1.0.0 is available. You have 1.0.0."
 */
export function versionLabel(version: string, build: string | null | undefined): string {
  return build ? `${version} (build ${build})` : version
}

/** What is happening with an install the person asked for, if anything. */
function UpdateProgress({
  update,
  installing,
  started,
  error,
  onDownloadPage,
}: {
  update: UpdateInfo
  installing: boolean
  started: UpdateStarted | null
  error: string | null
  onDownloadPage: () => void
}) {
  if (started) {
    return (
      <p className="update-progress" role="status">
        {started.platform === 'macos'
          ? `The installer is open in Finder. Drag AICOUNTLY Remote into Applications and replace the old version. This window closes in ${started.closesInSeconds} seconds.`
          : `The installer is starting. AICOUNTLY Remote closes in ${started.closesInSeconds} seconds so it can be updated, and the installer can open it again when it finishes.`}
      </p>
    )
  }

  if (installing) {
    return (
      <p className="update-progress" role="status">
        Downloading AICOUNTLY Remote {versionLabel(update.latestVersion, update.latestBuild)}. This can take a minute.
      </p>
    )
  }

  if (error) {
    return (
      <div className="notice notice--danger update-progress" role="alert">
        <p>{error}</p>
        <p>
          You can also download it yourself.{' '}
          <button type="button" className="link-button" onClick={onDownloadPage}>
            Open the download page
          </button>
        </p>
      </div>
    )
  }

  if (!update.installer) {
    return (
      <p className="update-progress">
        Installing opens the download page, where you can get the right version for this computer.
      </p>
    )
  }

  return null
}
