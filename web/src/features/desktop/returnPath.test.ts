import { describe, expect, it, beforeEach } from 'vitest'

import { consumeDesktopSignInReturnPath, stashDesktopSignInReturnPath } from './returnPath'

describe('desktop-signin return path', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('stashes the path and query when the deep link is opened', () => {
    stashDesktopSignInReturnPath({ pathname: '/desktop-signin', search: '?code=ABCD-1234' })

    expect(consumeDesktopSignInReturnPath()).toBe('/desktop-signin?code=ABCD-1234')
  })

  it('is a no-op on every other page', () => {
    stashDesktopSignInReturnPath({ pathname: '/', search: '' })
    stashDesktopSignInReturnPath({ pathname: '/devices', search: '?foo=bar' })

    expect(consumeDesktopSignInReturnPath()).toBeNull()
  })

  it('does not stash a bare visit with no code', () => {
    stashDesktopSignInReturnPath({ pathname: '/desktop-signin', search: '' })

    expect(consumeDesktopSignInReturnPath()).toBeNull()
  })

  it('is consumed exactly once', () => {
    stashDesktopSignInReturnPath({ pathname: '/desktop-signin', search: '?code=ABCD-1234' })

    expect(consumeDesktopSignInReturnPath()).toBe('/desktop-signin?code=ABCD-1234')
    expect(consumeDesktopSignInReturnPath()).toBeNull()
  })
})
