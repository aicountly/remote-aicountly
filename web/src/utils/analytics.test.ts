import { describe, expect, it } from 'vitest'

import { routePattern } from './analytics'

describe('analytics route patterns (I-17)', () => {
  it('never reports an invitation secret, a room id or a session id', () => {
    expect(routePattern('/join/Zk3pQ9secretinvitationtoken')).toBe('/join/:token')
    expect(routePattern('/room/6f1b6b0c-0000-4000-8000-000000000001')).toBe('/room/:uuid')
    expect(routePattern('/sessions/6f1b6b0c-0000-4000-8000-000000000001')).toBe('/sessions/:uuid')
  })

  it('keeps routes that carry no secret as they are', () => {
    expect(routePattern('/')).toBe('/')
    expect(routePattern('/sessions')).toBe('/sessions')
    expect(routePattern('/sessions/history')).toBe('/sessions/history')
    expect(routePattern('/admin/policy')).toBe('/admin/policy')
    expect(routePattern('/join')).toBe('/join')
  })
})
