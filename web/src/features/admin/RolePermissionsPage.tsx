import { useCallback, useEffect, useState } from 'react'
import { ShieldAlert } from 'lucide-react'

import { fetchRolePermissions, updateRolePermissions } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { useRemote } from '../../app/RemoteProvider'
import EmptyState from '../../components/ui/EmptyState'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import { PERMISSIONS } from '../../types/remote'
import { describePermission } from '../../utils/format'

/**
 * Role-level Remote permissions (§32).
 *
 * The layer between the baseline and a per-user override: a rule set here
 * applies to everybody carrying that AICOUNTLY role in this organisation, so it
 * is where "our field staff never share an entire screen" belongs — rather than
 * as thirty individual overrides that drift the moment somebody joins.
 *
 * The same rule as the user screen still holds and is still shown: a role grant
 * cannot exceed what company policy permits, because the capability mask is
 * applied after every grant.
 */

type Effect = 'ALLOW' | 'DENY' | 'INHERIT'

interface RoleRule {
  effect: string
  inheritedFromPlatform: boolean
}

export default function RolePermissionsPage() {
  const { companyId, scopeType, can, policy } = useRemote()

  const [roles, setRoles] = useState<Record<string, Record<string, RoleRule>>>({})
  const [catalog, setCatalog] = useState<Record<string, string[]>>({})
  const [selectedRole, setSelectedRole] = useState<string | null>(null)
  const [newRole, setNewRole] = useState('')
  const [changes, setChanges] = useState<Record<string, Effect>>({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<RemoteApiError | null>(null)

  const load = useCallback(async () => {
    if (companyId === null) {
      setLoading(false)

      return
    }

    setLoading(true)

    try {
      const result = await fetchRolePermissions(companyId)
      setRoles(result.roles ?? {})
      setCatalog(result.catalog ?? {})
      setError(null)

      setSelectedRole((current) => current ?? Object.keys(result.roles ?? {})[0] ?? null)
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'Role permissions could not be loaded.', 0),
      )
    } finally {
      setLoading(false)
    }
  }, [companyId])

  useEffect(() => {
    void load()
  }, [load])

  if (scopeType === 'PERSONAL' || companyId === null) {
    return (
      <div className="page">
        <EmptyState
          title="Choose an organisation"
          description="Role permissions belong to an organisation. Switch from Personal using the selector at the top of the page."
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
              'You do not have permission to view Remote role permissions for this organisation.',
              403,
            )
          }
        />
      </div>
    )
  }

  const readOnly = !can(PERMISSIONS.POLICY_MANAGE)
  const roleKeys = Object.keys(roles)

  async function save() {
    if (companyId === null || selectedRole === null) return

    setSaving(true)

    try {
      await updateRolePermissions(companyId, selectedRole, changes)
      setChanges({})
      await load()
    } catch (err) {
      setError(err instanceof RemoteApiError ? err : null)
    } finally {
      setSaving(false)
    }
  }

  function currentValue(permission: string): Effect {
    if (changes[permission]) return changes[permission]

    const rule = selectedRole ? roles[selectedRole]?.[permission] : undefined

    return (rule?.effect as Effect) ?? 'INHERIT'
  }

  return (
    <div className="page">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Role permissions</h1>
          <p className="muted">
            Rules that apply to everyone with a given AICOUNTLY role in{' '}
            {policy?.companyName ?? 'this organisation'}.
          </p>
        </header>

        {error ? <RestrictionNotice error={error} /> : null}

        <div className="notice notice--info">
          <p className="notice__title">Company policy still wins</p>
          <p className="notice__body">
            A role grant cannot exceed what the organisation's Remote policy permits. Where a capability is
            switched off there, granting it here has no effect.
          </p>
        </div>

        {loading ? (
          <div className="skeleton" style={{ height: 360 }} />
        ) : (
          <section className="card">
            <div className="card__header">
              <div>
                <h2 className="card__title">Roles</h2>
                <p className="card__subtitle">
                  {roleKeys.length === 0
                    ? 'No role-specific rules yet — everyone gets the AICOUNTLY defaults.'
                    : `${roleKeys.length} role${roleKeys.length === 1 ? '' : 's'} with rules`}
                </p>
              </div>
            </div>

            <div className="card__body stack">
              <div className="row row--wrap">
                {roleKeys.map((role) => (
                  <button
                    key={role}
                    type="button"
                    className={role === selectedRole ? 'preset preset--selected' : 'preset'}
                    onClick={() => {
                      setSelectedRole(role)
                      setChanges({})
                    }}
                  >
                    {role}
                  </button>
                ))}
              </div>

              {!readOnly ? (
                <div className="row">
                  <label className="sr-only" htmlFor="new-role">
                    Role key
                  </label>
                  <input
                    id="new-role"
                    className="input"
                    value={newRole}
                    onChange={(event) => setNewRole(event.target.value.toUpperCase())}
                    placeholder="Add a role key, e.g. FIELD_STAFF"
                    maxLength={64}
                  />
                  <button
                    type="button"
                    className="btn btn--secondary"
                    disabled={!newRole.trim()}
                    onClick={() => {
                      const key = newRole.trim()
                      setRoles((current) => ({ ...current, [key]: current[key] ?? {} }))
                      setSelectedRole(key)
                      setChanges({})
                      setNewRole('')
                    }}
                  >
                    Add role
                  </button>
                </div>
              ) : null}

              <p className="field__hint">
                The role key must match the role AICOUNTLY assigns the user in this organisation. Rules for a
                role nobody carries simply never apply.
              </p>
            </div>
          </section>
        )}

        {selectedRole ? (
          <section className="card">
            <div className="card__header">
              <div>
                <h2 className="card__title">{selectedRole}</h2>
                <p className="card__subtitle">
                  Inherit leaves the AICOUNTLY default in place. Allow and Deny are explicit.
                </p>
              </div>

              {!readOnly ? (
                <button
                  type="button"
                  className="btn btn--primary btn--sm"
                  disabled={saving || Object.keys(changes).length === 0}
                  onClick={() => void save()}
                >
                  {saving ? 'Saving…' : 'Save changes'}
                </button>
              ) : null}
            </div>

            <div className="card__body stack">
              {Object.entries(catalog).map(([group, permissions]) => (
                <div key={group} className="permission-group">
                  <h3 className="permission-group__title">{group}</h3>

                  <ul className="permission-group__list">
                    {permissions.map((permission) => {
                      const rule = roles[selectedRole]?.[permission]

                      return (
                        <li key={permission} className="permission-row">
                          <div className="permission-row__label">
                            <span className="permission-row__name">{describePermission(permission)}</span>

                            {rule?.inheritedFromPlatform ? (
                              <span className="permission-row__blocked">
                                <ShieldAlert size={13} aria-hidden="true" />
                                Currently set platform-wide — saving here overrides it for this organisation
                              </span>
                            ) : null}
                          </div>

                          <div className="permission-row__control">
                            <label className="sr-only" htmlFor={`role-perm-${permission}`}>
                              {describePermission(permission)}
                            </label>
                            <select
                              id={`role-perm-${permission}`}
                              className="select select--narrow"
                              value={currentValue(permission)}
                              disabled={readOnly}
                              onChange={(event) =>
                                setChanges((current) => ({
                                  ...current,
                                  [permission]: event.target.value as Effect,
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
                </div>
              ))}
            </div>
          </section>
        ) : null}
      </div>
    </div>
  )
}
