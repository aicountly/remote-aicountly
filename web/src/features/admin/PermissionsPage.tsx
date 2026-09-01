import { useCallback, useEffect, useMemo, useState } from 'react'
import { Search, ShieldAlert } from 'lucide-react'

import { fetchPermissionMatrix, updateUserPermissions } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { useRemote } from '../../app/RemoteProvider'
import Modal from '../../components/ui/Modal'
import EmptyState from '../../components/ui/EmptyState'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import { PERMISSIONS } from '../../types/remote'
import type { PermissionMatrixUser } from '../../types/remote'
import { describePermission } from '../../utils/format'

/**
 * Per-user Remote permissions (§41).
 *
 * The single most important thing this screen has to communicate is the rule it
 * cannot break: **a user-level grant can only ever narrow what company policy
 * already allows.** So a permission the organisation has switched off is shown
 * struck through with the reason, and setting it to Allow does nothing — which
 * the drawer says before the administrator tries.
 *
 * Effective answers come from the server, computed by the same resolver the API
 * enforces with. The page never derives one.
 */

const SUMMARY_COLUMNS = [
  { permission: PERMISSIONS.ACCESS, label: 'Remote access' },
  { permission: PERMISSIONS.SCREEN_SHARE, label: 'Share screen' },
  { permission: PERMISSIONS.SCREEN_VIEW, label: 'View screen' },
  { permission: PERMISSIONS.EXTERNAL_INVITE, label: 'External invite' },
  { permission: PERMISSIONS.SESSION_HISTORY_COMPANY, label: 'History' },
  { permission: PERMISSIONS.POLICY_MANAGE, label: 'Administration' },
]

export default function PermissionsPage() {
  const { companyId, scopeType, can, policy } = useRemote()

  const [users, setUsers] = useState<PermissionMatrixUser[]>([])
  const [catalog, setCatalog] = useState<Record<string, string[]>>({})
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<RemoteApiError | null>(null)
  const [editing, setEditing] = useState<PermissionMatrixUser | null>(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(
    async (term = '') => {
      if (companyId === null) {
        setLoading(false)

        return
      }

      setLoading(true)

      try {
        const { data } = await fetchPermissionMatrix(companyId, { search: term, limit: 50 })
        setUsers(data.users ?? [])
        setCatalog(data.catalog ?? {})
        setError(null)
      } catch (err) {
        setError(
          err instanceof RemoteApiError
            ? err
            : new RemoteApiError('UNKNOWN', 'Permissions could not be loaded.', 0),
        )
      } finally {
        setLoading(false)
      }
    },
    [companyId],
  )

  useEffect(() => {
    void load()
  }, [load])

  // Debounced search: a request per keystroke against a permission matrix is
  // needlessly expensive on both ends.
  useEffect(() => {
    const timer = setTimeout(() => void load(search.trim()), 300)

    return () => clearTimeout(timer)
  }, [search, load])

  /**
   * Which permissions the organisation itself has switched off.
   *
   * A user-level Allow for one of these has no effect, so the drawer marks them
   * rather than letting an administrator believe they changed something.
   */
  const blockedByPolicy = useMemo(() => {
    if (!policy) return new Set<string>()

    const blocked = new Set<string>()
    const add = (permitted: boolean, ...names: string[]) => {
      if (!permitted) names.forEach((name) => blocked.add(name))
    }

    add(policy.allowSafeShare, PERMISSIONS.SAFE_SHARE)
    add(policy.allowBrowserTab, PERMISSIONS.BROWSER_TAB_SHARE)
    add(policy.allowApplicationWindow, PERMISSIONS.WINDOW_SHARE)
    add(policy.allowEntireMonitor, PERMISSIONS.MONITOR_SHARE)
    add(policy.allowMicrophone, PERMISSIONS.MICROPHONE_SHARE)
    add(policy.allowSystemAudio, PERMISSIONS.SYSTEM_AUDIO_SHARE)
    add(policy.allowTextChat, PERMISSIONS.CHAT_USE)
    add(policy.allowAnnotation, PERMISSIONS.ANNOTATION_USE)
    add(policy.allowFileTransfer, PERMISSIONS.FILE_SEND, PERMISSIONS.FILE_RECEIVE)
    add(policy.allowExternalGuest, PERMISSIONS.EXTERNAL_INVITE)
    add(policy.allowAicountlySupport, PERMISSIONS.SUPPORT_REQUEST)
    add(policy.allowRecording, PERMISSIONS.RECORDING_START)

    return blocked
  }, [policy])

  if (scopeType === 'PERSONAL' || companyId === null) {
    return (
      <div className="page">
        <EmptyState
          title="Choose an organisation"
          description="Remote permissions belong to an organisation. Switch from Personal using the selector at the top of the page."
        />
      </div>
    )
  }

  if (!can(PERMISSIONS.POLICY_VIEW)) {
    return (
      <div className="page">
        <RestrictionNotice
          error={
            new RemoteApiError(
              'ADMIN_PERMISSION_DENIED',
              'You do not have permission to view Remote permissions for this organisation.',
              403,
            )
          }
        />
      </div>
    )
  }

  const readOnly = !can(PERMISSIONS.POLICY_MANAGE)

  async function saveOverrides(user: PermissionMatrixUser, changes: Record<string, 'ALLOW' | 'DENY' | 'INHERIT'>) {
    if (companyId === null) return

    setSaving(true)

    try {
      const result = await updateUserPermissions(companyId, user.userUuid, changes)

      // The server returns the recomputed effective set, which is what makes
      // "your Allow had no effect" visible immediately rather than on reload.
      setUsers((current) =>
        current.map((entry) =>
          entry.userUuid === user.userUuid
            ? {
                ...entry,
                permissions: result.permissions,
                overrides: result.overrides as PermissionMatrixUser['overrides'],
              }
            : entry,
        ),
      )
      setEditing(null)
    } catch (err) {
      setError(err instanceof RemoteApiError ? err : null)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="page">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Remote permissions</h1>
          <p className="muted">
            What each person in {policy?.companyName ?? 'this organisation'} can do in AICOUNTLY Remote.
          </p>
        </header>

        {error ? <RestrictionNotice error={error} /> : null}

        <div className="notice notice--info">
          <p className="notice__title">Company policy restrictions cannot be overridden at user level</p>
          <p className="notice__body">
            Granting a permission here has no effect where the organisation’s Remote policy has switched that
            capability off. Change the policy first.
          </p>
        </div>

        <section className="card">
          <div className="card__header">
            <div className="field search-field">
              <label className="sr-only" htmlFor="permission-search">
                Search employee
              </label>
              <Search size={15} aria-hidden="true" />
              <input
                id="permission-search"
                className="input"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search employee…"
                autoComplete="off"
              />
            </div>
          </div>

          {loading ? (
            <div className="card__body stack stack--sm" aria-busy="true">
              {[0, 1, 2].map((index) => (
                <div key={index} className="skeleton" style={{ height: 48 }} />
              ))}
            </div>
          ) : users.length === 0 ? (
            <div className="card__body">
              <EmptyState
                title="No people to show"
                description="People appear here once they have used an AICOUNTLY product in this organisation."
              />
            </div>
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">User</th>
                    <th scope="col">Role</th>
                    {SUMMARY_COLUMNS.map((column) => (
                      <th key={column.permission} scope="col">
                        {column.label}
                      </th>
                    ))}
                    <th scope="col">
                      <span className="sr-only">Actions</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {users.map((user) => (
                    <tr key={user.userUuid}>
                      <td>
                        <span className="permission-user">{user.displayName}</span>
                        {user.email ? <span className="permission-email">{user.email}</span> : null}
                      </td>
                      <td>{user.isCompanyAdmin ? 'Administrator' : user.roleKey}</td>

                      {SUMMARY_COLUMNS.map((column) => (
                        <td key={column.permission}>
                          <PermissionDot granted={user.permissions[column.permission] === true} />
                        </td>
                      ))}

                      <td>
                        <button
                          type="button"
                          className="btn btn--secondary btn--sm"
                          onClick={() => setEditing(user)}
                        >
                          {readOnly ? 'View' : 'Edit'}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>

      {editing ? (
        <PermissionDrawer
          user={editing}
          catalog={catalog}
          blockedByPolicy={blockedByPolicy}
          readOnly={readOnly}
          saving={saving}
          onClose={() => setEditing(null)}
          onSave={(changes) => void saveOverrides(editing, changes)}
        />
      ) : null}
    </div>
  )
}

function PermissionDot({ granted }: { granted: boolean }) {
  return (
    <span className={granted ? 'permission-dot permission-dot--on' : 'permission-dot'} role="img"
      aria-label={granted ? 'Allowed' : 'Not allowed'}
    />
  )
}

function PermissionDrawer({
  user,
  catalog,
  blockedByPolicy,
  readOnly,
  saving,
  onClose,
  onSave,
}: {
  user: PermissionMatrixUser
  catalog: Record<string, string[]>
  blockedByPolicy: Set<string>
  readOnly: boolean
  saving: boolean
  onClose: () => void
  onSave: (changes: Record<string, 'ALLOW' | 'DENY' | 'INHERIT'>) => void
}) {
  const [changes, setChanges] = useState<Record<string, 'ALLOW' | 'DENY' | 'INHERIT'>>({})

  function currentValue(permission: string): 'ALLOW' | 'DENY' | 'INHERIT' {
    return changes[permission] ?? user.overrides[permission] ?? 'INHERIT'
  }

  return (
    <Modal
      open
      title={user.displayName}
      description={`${user.isCompanyAdmin ? 'Administrator' : user.roleKey}${user.email ? ` · ${user.email}` : ''}`}
      onClose={onClose}
      size="lg"
      footer={
        <div className="row row--between row--wrap">
          <button type="button" className="btn btn--ghost" onClick={onClose}>
            Close
          </button>
          {!readOnly ? (
            <button
              type="button"
              className="btn btn--primary"
              disabled={saving || Object.keys(changes).length === 0}
              onClick={() => onSave(changes)}
            >
              {saving ? 'Saving…' : 'Save changes'}
            </button>
          ) : null}
        </div>
      }
    >
      <div className="stack">
        {Object.entries(catalog).map(([group, permissions]) => (
          <section key={group} className="permission-group">
            <h3 className="permission-group__title">{group}</h3>

            <ul className="permission-group__list">
              {permissions.map((permission) => {
                const blocked = blockedByPolicy.has(permission)
                const effective = user.permissions[permission] === true

                return (
                  <li key={permission} className="permission-row">
                    <div className="permission-row__label">
                      <span className={blocked ? 'permission-row__name permission-row__name--blocked' : 'permission-row__name'}>
                        {describePermission(permission)}
                      </span>

                      {blocked ? (
                        <span className="permission-row__blocked">
                          <ShieldAlert size={13} aria-hidden="true" />
                          Switched off by company policy — a grant here has no effect
                        </span>
                      ) : (
                        <span className="permission-row__effective">
                          Currently {effective ? 'allowed' : 'not allowed'}
                        </span>
                      )}
                    </div>

                    <div className="permission-row__control">
                      <label className="sr-only" htmlFor={`perm-${permission}`}>
                        {describePermission(permission)}
                      </label>
                      <select
                        id={`perm-${permission}`}
                        className="select select--narrow"
                        value={currentValue(permission)}
                        disabled={readOnly}
                        onChange={(event) =>
                          setChanges((current) => ({
                            ...current,
                            [permission]: event.target.value as 'ALLOW' | 'DENY' | 'INHERIT',
                          }))
                        }
                      >
                        <option value="INHERIT">Inherit</option>
                        <option value="ALLOW">Allow</option>
                        <option value="DENY">Deny</option>
                      </select>
                    </div>
                  </li>
                )
              })}
            </ul>
          </section>
        ))}
      </div>
    </Modal>
  )
}
