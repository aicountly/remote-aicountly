/**
 * Whether AICOUNTLY Remote works with organisations at all.
 *
 * Not for now. Remote belongs to the person signed in, and to nothing set up in
 * Manage: no organisation to choose, no organisation's policy, administrators,
 * users and access, or audit trail to manage, and no organisation a launch
 * from another AICOUNTLY product can carry a session into. Every session is
 * Personal. The desktop application registers a computer to the person, so a
 * session in any other scope could not be controlled from it anyway.
 *
 * One switch rather than deleted code: the API still understands company
 * scope, the shared company-access module is still maintained across the
 * AICOUNTLY products, and bringing organisations back is a decision, not a
 * rewrite. Everything that would show an organisation — the switcher, the
 * session-context choice, the administration pages and their routes, the
 * organisation filters and columns — is behind this.
 */
export const ORGANISATIONS_ENABLED: boolean = false

/**
 * The account menu's "Switch company" asks the header's scope switcher to open.
 *
 * An event rather than lifted state: the two sit side by side in the header, and the switcher
 * already owns the organisation list, the outside-click handling and the workspace-companies
 * modal. Books does the same thing (`books:open-company-switcher`). Both ends are behind
 * ORGANISATIONS_ENABLED, so with organisations off nothing dispatches it and nothing listens.
 */
export const OPEN_COMPANY_SWITCHER_EVENT = 'remote:open-company-switcher'
