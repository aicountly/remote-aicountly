import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  Building2,
  ChevronDown,
  ClipboardList,
  Check,
  History,
  LayoutDashboard,
  LifeBuoy,
  Link2,
  LogOut,
  Menu,
  MonitorSmartphone,
  MonitorUp,
  ScrollText,
  Settings,
  ShieldCheck,
  UserCog,
  Users,
  X,
} from 'lucide-react'

import { useAuth } from '../auth/AuthProvider'
import { useRemote } from './RemoteProvider'
import { PERMISSIONS } from '../types/remote'
import AicountlyLogo from '../components/brand/AicountlyLogo'
import { AppLauncher } from '../components/AppLauncher'

/**
 * The application frame: AICOUNTLY header, organisation switcher, navigation.
 *
 * Two rules shape it:
 *   * **administration is permission-gated** (§32) — an ordinary employee never
 *     sees policy or audit items at all, rather than seeing them and being
 *     refused;
 *   * **the organisation is always visible** (§6B) — a person sharing their
 *     screen should never have to wonder which company the session belongs to.
 */
export default function AppShell() {
  const { signOut } = useAuth()
  const { bootstrap, policy, scopeType, companyId, switchScope, can } = useRemote()
  const navigate = useNavigate()

  const [scopeMenuOpen, setScopeMenuOpen] = useState(false)
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const scopeRef = useRef<HTMLDivElement>(null)

  // Close the organisation menu on an outside click or Escape — a dropdown that
  // only closes by selecting something is a trap for keyboard users.
  useEffect(() => {
    if (!scopeMenuOpen) return

    const onPointerDown = (event: MouseEvent) => {
      if (!scopeRef.current?.contains(event.target as Node)) setScopeMenuOpen(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setScopeMenuOpen(false)
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [scopeMenuOpen])

  const companies = bootstrap?.companies ?? []
  const activeCompany = companies.find((company) => company.companyId === companyId)
  const scopeLabel = scopeType === 'PERSONAL' ? 'Personal' : (activeCompany?.name ?? 'Organisation')

  const showAdministration =
    can(PERMISSIONS.POLICY_VIEW) || can(PERMISSIONS.POLICY_MANAGE) || can(PERMISSIONS.AUDIT_VIEW)

  const isSupportAgent = bootstrap?.user.isSupportAgent ?? false

  const navItems = [
    { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true },
    { to: '/start', label: 'Start session', icon: MonitorUp },
    { to: '/join', label: 'Join session', icon: Link2 },
    ...(can(PERMISSIONS.SUPPORT_REQUEST) || isSupportAgent
      ? [{ to: '/support', label: 'Support requests', icon: LifeBuoy }]
      : []),
    { to: '/sessions', label: 'Sessions', icon: History },
    // Only for somebody who has a reason to see it: registering, managing or
    // connecting to a machine. A person with none of the three is not shown a
    // page that would be empty of anything they could do (§52).
    ...(can(PERMISSIONS.DEVICE_MANAGE) ||
    can(PERMISSIONS.DEVICE_ENROL) ||
    can(PERMISSIONS.UNATTENDED_ACCESS)
      ? [{ to: '/devices', label: 'Computers', icon: MonitorSmartphone }]
      : []),
    ...(showAdministration
      ? [
          { to: '/admin/policy', label: 'Remote policy', icon: ShieldCheck },
          { to: '/admin/permissions', label: 'User permissions', icon: Users },
          { to: '/admin/roles', label: 'Role permissions', icon: UserCog },
          ...(can(PERMISSIONS.AUDIT_VIEW) ? [{ to: '/admin/audit', label: 'Audit trail', icon: ScrollText }] : []),
        ]
      : []),
    { to: '/settings', label: 'Settings', icon: Settings },
  ]

  async function chooseScope(nextScope: 'PERSONAL' | 'COMPANY', nextCompanyId: number | null) {
    setScopeMenuOpen(false)
    await switchScope(nextScope, nextCompanyId)
    // Policy differs per organisation, so a screen showing the previous one's
    // data would be misleading. Return to the dashboard, which always reloads.
    navigate('/')
  }

  return (
    <div className="shell">
      <header className="shell__header">
        <button
          type="button"
          className="btn btn--ghost btn--sm shell__nav-toggle"
          onClick={() => setMobileNavOpen((open) => !open)}
          aria-expanded={mobileNavOpen}
          aria-label={mobileNavOpen ? 'Close navigation' : 'Open navigation'}
        >
          {mobileNavOpen ? <Menu size={18} aria-hidden="true" /> : <Menu size={18} aria-hidden="true" />}
        </button>

        <AppLauncher />

        <div className="shell__brand">
          <AicountlyLogo />
          <span className="shell__product">Remote</span>
        </div>

        <div className="shell__scope" ref={scopeRef}>
          <button
            type="button"
            className="scope-button"
            onClick={() => setScopeMenuOpen((open) => !open)}
            aria-haspopup="menu"
            aria-expanded={scopeMenuOpen}
          >
            <Building2 size={15} aria-hidden="true" />
            <span className="scope-button__label truncate">{scopeLabel}</span>
            <ChevronDown size={14} aria-hidden="true" />
          </button>

          {scopeMenuOpen ? (
            <div className="scope-menu" role="menu">
              <p className="scope-menu__heading">Remote session context</p>

              <button
                type="button"
                role="menuitemradio"
                aria-checked={scopeType === 'PERSONAL'}
                className="scope-menu__item"
                onClick={() => void chooseScope('PERSONAL', null)}
              >
                <span>
                  <span className="scope-menu__name">Personal</span>
                  <span className="scope-menu__hint">Not linked to an organisation</span>
                </span>
                {scopeType === 'PERSONAL' ? <Check size={15} aria-hidden="true" /> : null}
              </button>

              {companies.map((company) => (
                <button
                  key={company.companyId}
                  type="button"
                  role="menuitemradio"
                  aria-checked={companyId === company.companyId}
                  className="scope-menu__item"
                  onClick={() => void chooseScope('COMPANY', company.companyId)}
                >
                  <span>
                    <span className="scope-menu__name">{company.name}</span>
                    <span className="scope-menu__hint">
                      {company.isCompanyAdmin ? 'Administrator' : 'Member'}
                    </span>
                  </span>
                  {companyId === company.companyId ? <Check size={15} aria-hidden="true" /> : null}
                </button>
              ))}

              {companies.length === 0 ? (
                <p className="scope-menu__empty">
                  No AICOUNTLY organisations are linked to your account yet. Start Remote from a product
                  such as AICOUNTLY Books to bring its organisation across.
                </p>
              ) : null}
            </div>
          ) : null}
        </div>

        <div className="shell__actions">
          {policy && !policy.remoteEnabled ? (
            <span className="badge badge--danger">Remote disabled</span>
          ) : null}

          <div className="shell__user">
            <span className="shell__user-name truncate">{bootstrap?.user.displayName ?? '—'}</span>
            <button type="button" className="btn btn--ghost btn--sm" onClick={signOut}>
              <LogOut size={15} aria-hidden="true" />
              <span className="shell__logout-label">Log out</span>
            </button>
          </div>
        </div>
      </header>

      <div className="shell__body">
        <nav
          className={mobileNavOpen ? 'shell__nav shell__nav--open' : 'shell__nav'}
          aria-label="AICOUNTLY Remote"
        >
          <ul className="shell__nav-list">
            {navItems.map(({ to, label, icon: Icon, end }) => (
              <li key={to}>
                <NavLink
                  to={to}
                  end={end}
                  className={({ isActive }) => (isActive ? 'shell__nav-link is-active' : 'shell__nav-link')}
                  onClick={() => setMobileNavOpen(false)}
                >
                  <Icon size={16} aria-hidden="true" />
                  <span>{label}</span>
                </NavLink>
              </li>
            ))}
          </ul>

          <p className="shell__nav-footnote">
            <ClipboardList size={13} aria-hidden="true" />
            Browser assistance. No installation required.
          </p>
        </nav>

        {mobileNavOpen ? (
          <button
            type="button"
            className="shell__nav-scrim"
            aria-label="Close navigation"
            onClick={() => setMobileNavOpen(false)}
          >
            <X size={0} aria-hidden="true" />
          </button>
        ) : null}

        <main className="shell__main">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
