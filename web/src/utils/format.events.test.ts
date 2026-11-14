import { describe, expect, it } from 'vitest'

// The server's own list of events, as text.
import eventTypeSource from '../../../backend/app/Domain/Audit/EventType.php?raw'
import { describeEvent } from './format'

/**
 * Every event the server records has wording here.
 *
 * `describeEvent` falls back to the server's own name in lower case, so a missing label does not
 * fail anything — it shows "control granted" on a session's timeline beside sentences. Nineteen
 * were missing, the control events among them, which are the ones somebody reads a timeline for.
 * The list is read from the server's own source, so an event added there fails this until it has
 * a sentence.
 */
function serverEventTypes(): string[] {
  return [...eventTypeSource.matchAll(/public const \w+\s*=\s*'([A-Z0-9_]+)'/g)].map((match) => match[1])
}

describe('the wording of events', () => {
  it('reads the server’s list', () => {
    const events = serverEventTypes()

    expect(events.length).toBeGreaterThan(60)
    expect(events).toContain('CONTROL_GRANTED')
    expect(events).toContain('SESSION_CREATED')
  })

  it('has a sentence for every event the server records', () => {
    const unworded = serverEventTypes().filter(
      (event) => describeEvent(event) === event.replace(/_/g, ' ').toLowerCase(),
    )

    expect(unworded).toEqual([])
  })

  it('says what happened to control in words', () => {
    expect(describeEvent('CONTROL_GRANTED')).toBe('Control of the computer allowed')
    expect(describeEvent('CONTROL_REVOKED')).toBe('Control of the computer stopped')
    expect(describeEvent('UNATTENDED_SESSION_STARTED')).toBe('Connected with nobody at the computer')
  })
})
