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
