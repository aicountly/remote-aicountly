import { LatencyMeter } from '../../ice-kit/index.js'

/**
 * How far this browser is from each relay node, measured after a connection (ice-kit LatencyMeter) and
 * remembered for hours. Sent with the next request for credentials so a Mumbai-near and a Delhi-near user
 * start on different nodes; advice only — the server never trusts it for tier or capacity.
 */
export const latencyMeter = new LatencyMeter()

/** The request body that carries the last measurement, or undefined when there is none worth sending. */
export function latencyBody(): { rtt: Record<string, number> } | undefined {
  const rtt = latencyMeter.latest()

  return rtt ? { rtt } : undefined
}
