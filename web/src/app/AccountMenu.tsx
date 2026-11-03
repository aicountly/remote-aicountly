/**
 * The account menu, behind the avatar in the top right.
 *
 * The layout is the suite-wide one — the same blocks in the same order in every
 * AICOUNTLY product, so the menu does not rearrange itself as people move
 * between them (see `AICOUNTLY_ACCOUNT_MENU.md` in the Saas Common Handler):
 *
 *   1. who you are      photo, name, e-mail, masked mobile, Cust ID
 *   2. your accounts    the others signed in on this browser, and "Use another"
 *   3. company          the header's own organisation switcher, to the left
 *   4. appearance       Remote has no in-menu theme switch
 *   5. links            Manage profile, Manage Account
 *   6. sign out
 *
 * The name, the full e-mail and the Cust ID come from `/bootstrap`, which is
 * what this product knows about the signed-in person. The masked mobile comes
 * from the roster cookie — the only place a product has it, and masked is the
 * only form that belongs in a menu.
 *
 * There is no photo: Remote has no authenticated user-logo route to fetch one
 * from, so the avatar is initials, which the contract allows as the fallback.
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import { ChevronDown, ExternalLink, LogOut, User, UserCog, UserPlus, Users } from 'lucide-react'

import { useAuth } from '../auth/AuthProvider'
import { buildChooseAccountUrl, canAddAccount, performAccountSwitch, readAccountRoster } from '../auth/accounts'
import type { RosterAccount } from '../auth/accounts'
import { stopConnect } from '../connect/connect'
import { manageAccountPath, manageProfilePath, openManagePortal } from '../services/appLauncher'
import { useRemote } from './RemoteProvider'

/**
 * First letters of up to two words — the avatar when there is no photo.
 *
 * Splits on `@`, `.`, `_` and `-` as well as whitespace, so an account with no
 * name on file still gets something out of its e-mail.
 */
function initialsOf(source: string | null | undefined): string {
  return String(source ?? '')
    .split(/[\s@._-]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

function accountLabel(account: RosterAccount): string {
  return account.name || account.maskedEmail || `Account ${account.uuid}`
}

export default function AccountMenu() {
  const { signOut } = useAuth()
  const { bootstrap } = useRemote()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)

  // Read when the menu opens, not on every render: only a full page load can
  // change the cookie, because the portal is what writes it.
  const [roster, setRoster] = useState<RosterAccount[]>([])
  useEffect(() => {
    if (open) setRoster(readAccountRoster())
  }, [open])

  // Outside click and Escape, as the organisation menu beside it does. A
  // dropdown that only closes by selecting something is a trap.
  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: MouseEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  const name = bootstrap?.user.displayName ?? ''
  const email = bootstrap?.user.email ?? ''
  // The portal (my.aicountly) user id — what support asks for as the "Cust ID".
  const custId = bootstrap?.user.uuid || null
  const initials = initialsOf(name || email)

  // The signed-in account's own roster entry carries the masked mobile. Matched
  // on the subscriber id where both are known, so a stale cookie cannot label
  // this session with somebody else's number.
  const activeEntry = useMemo(
    () =>
      roster.find((account) => account.active && String(account.uuid) === String(custId)) ??
      roster.find((account) => account.active) ??
      null,
    [roster, custId],
  )
  const others = useMemo(() => roster.filter((account) => !account.active), [roster])

  function openManage(path: string) {
    setOpen(false)
    openManagePortal(path)
  }

  function switchTo(uuid?: number) {
    // Connect goes first: its widget and session callback belong to the account
    // being left, exactly as at sign-out.
    stopConnect()
    setOpen(false)
    performAccountSwitch(uuid)
  }

  /**
   * The portal chooser, for a browser with no readable roster.
   *
   * Built on the click rather than in the markup: the URL carries a single-use
   * sign-in nonce that `buildChooseAccountUrl()` stores for this tab, and the
   * one that travels has to be the one still pending when we leave.
   */
  function chooseAccount() {
    stopConnect()
    setOpen(false)
    window.location.assign(buildChooseAccountUrl())
  }

  return (
    <div className="account-menu" ref={rootRef}>
      <button
        ref={triggerRef}
        type="button"
        className="account-button"
        aria-haspopup="menu"
        aria-expanded={open}
        // Includes the name shown on the button (WCAG 2.5.3, label in name).
        aria-label={name ? `Account menu for ${name}` : 'Account menu'}
        onClick={() => setOpen((value) => !value)}
      >
        <span className="account-avatar" aria-hidden="true">
          {initials || <User size={15} />}
        </span>
        {name ? <span className="account-button__name truncate">{name}</span> : null}
        <ChevronDown size={14} aria-hidden="true" />
      </button>

      {open ? (
        <div className="account-menu__panel" role="menu" aria-label="Account menu">
          {name || email || custId ? (
            <div className="account-menu__identity">
              <span className="account-avatar account-avatar--lg" aria-hidden="true">
                {initials || <User size={18} />}
              </span>
              <span className="account-menu__identity-text">
                <span className="account-menu__name truncate">{name || 'Signed in'}</span>
                {email ? <span className="account-menu__meta truncate">{email}</span> : null}
                {activeEntry?.maskedMobile ? (
                  <span
                    className="account-menu__meta account-menu__numeric truncate"
                    title="Your registered mobile number"
                  >
                    {activeEntry.maskedMobile}
                  </span>
                ) : null}
                {custId ? (
                  <span className="account-menu__meta truncate" title="Your AICOUNTLY customer ID">
                    Cust ID: <span className="account-menu__numeric">{custId}</span>
                  </span>
                ) : null}
              </span>
            </div>
          ) : null}

          <div className="account-menu__section">
            <p className="account-menu__heading">Accounts</p>

            {others.map((account) => (
              <button
                key={account.uuid}
                type="button"
                role="menuitem"
                className="account-menu__item"
                onClick={() => switchTo(account.uuid)}
              >
                <span className="account-avatar account-avatar--sm" aria-hidden="true">
                  {initialsOf(account.name || account.maskedEmail) || <User size={13} />}
                </span>
                <span className="account-menu__item-text">
                  <span className="account-menu__item-name truncate">{accountLabel(account)}</span>
                  {account.maskedEmail ? (
                    <span className="account-menu__item-meta truncate">{account.maskedEmail}</span>
                  ) : null}
                </span>
              </button>
            ))}

            {/* No readable roster — local development, the desktop application's
                own web view, or a browser signed in before the roster shipped.
                The portal chooser always knows the truth. */}
            {roster.length === 0 ? (
              <button type="button" role="menuitem" className="account-menu__item" onClick={chooseAccount}>
                <Users size={16} aria-hidden="true" />
                <span className="account-menu__item-text">Switch account</span>
              </button>
            ) : null}

            {/* Hidden once this browser holds five — the portal refuses a sixth. */}
            {canAddAccount(roster) ? (
              <button type="button" role="menuitem" className="account-menu__item" onClick={() => switchTo(undefined)}>
                <UserPlus size={16} aria-hidden="true" />
                <span className="account-menu__item-text">Use another account</span>
              </button>
            ) : null}
          </div>

          <div className="account-menu__section">
            <button
              type="button"
              role="menuitem"
              className="account-menu__item"
              title="Opens your profile in Manage in a new tab"
              onClick={() => openManage(manageProfilePath())}
            >
              <UserCog size={16} aria-hidden="true" />
              <span className="account-menu__item-text">Manage profile</span>
              <ExternalLink size={13} aria-hidden="true" />
              <span className="sr-only"> (opens in a new tab)</span>
            </button>
            <button
              type="button"
              role="menuitem"
              className="account-menu__item"
              title="Opens your account settings in Manage in a new tab"
              onClick={() => openManage(manageAccountPath())}
            >
              <Users size={16} aria-hidden="true" />
              <span className="account-menu__item-text">Manage Account</span>
              <ExternalLink size={13} aria-hidden="true" />
              <span className="sr-only"> (opens in a new tab)</span>
            </button>
          </div>

          {/* "Sign out", not "Log out": one wording across the suite, and it
              ends every account signed in on this browser, as the portal does. */}
          <div className="account-menu__section">
            <button
              type="button"
              role="menuitem"
              className="account-menu__item account-menu__item--danger"
              onClick={signOut}
            >
              <LogOut size={16} aria-hidden="true" />
              <span className="account-menu__item-text">Sign out</span>
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
