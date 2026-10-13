import { CompanyUsersAccessPanel, MyWorkspacesPanel } from '../../company-access/index.ts'
import { useRemote } from '../../app/RemoteProvider'
import EmptyState from '../../components/ui/EmptyState'
import { REGISTRY_APP_NAME, getCompanyRegistry } from '../../services/companyRegistry'
import { Building2 } from 'lucide-react'

/**
 * Users & Access — who may open the selected organisation in Remote.
 *
 * Read from and written to Manage's company registry (/api/v1/*); Remote keeps
 * no copy of it. The verified company owner grants, changes and revokes access
 * here; anyone else sees their own access and can ask for it. Remote's own
 * user and role permissions (Administration) are unchanged and stay
 * authoritative for what a person may do in Remote.
 */
export default function UsersAccessPage() {
  const { bootstrap, scopeType, companyId, refresh } = useRemote()
  const client = getCompanyRegistry()
  const company = bootstrap?.companies.find((c) => c.companyId === companyId) ?? null

  return (
    <div className="page">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Users &amp; Access</h1>
          <p className="muted">
            {company ? `Who can open ${company.name} in Remote.` : 'Who can open an organisation in Remote.'}
          </p>
        </header>

        {scopeType === 'COMPANY' && companyId ? (
          <section className="card">
            <CompanyUsersAccessPanel
              key={companyId}
              client={client}
              cmpId={companyId}
              appName={REGISTRY_APP_NAME}
              onChanged={() => void refresh()}
            />
          </section>
        ) : (
          <EmptyState
            icon={<Building2 size={22} />}
            title="Choose an organisation"
            description="Users & Access is per organisation. Pick one in the header to see who can open it in Remote."
          />
        )}

        <section className="card">
          <MyWorkspacesPanel client={client} onChanged={() => void refresh()} />
        </section>
      </div>
    </div>
  )
}
