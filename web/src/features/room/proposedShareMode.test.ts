import { describe, expect, it } from 'vitest'

import { proposedShareMode } from './proposedShareMode'

describe('proposedShareMode', () => {
  it('proposes what the session was started for', () => {
    expect(proposedShareMode('ENTIRE_MONITOR', ['SAFE_SHARE', 'BROWSER_TAB', 'ENTIRE_MONITOR'])).toBe('ENTIRE_MONITOR')
    expect(proposedShareMode('BROWSER_TAB', ['SAFE_SHARE', 'BROWSER_TAB'])).toBe('BROWSER_TAB')
  })

  it('falls back to the first thing allowed when the choice is no longer allowed', () => {
    expect(proposedShareMode('ENTIRE_MONITOR', ['SAFE_SHARE', 'BROWSER_TAB'])).toBe('SAFE_SHARE')
  })

  it('has an answer with no session choice or no policy', () => {
    expect(proposedShareMode(null, ['BROWSER_TAB'])).toBe('BROWSER_TAB')
    expect(proposedShareMode('ENTIRE_MONITOR', null)).toBe('SAFE_SHARE')
  })
})
