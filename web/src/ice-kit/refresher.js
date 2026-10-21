/**
 * Keeps a connection's TURN credentials ahead of their expiry.
 *
 * A relay refuses a NEW allocation presented with an expired credential (measured: the first thing
 * `bin/probe.php --expiry-test` reports). Whether it also drops an allocation that already exists when
 * its credential expires depends on the relay's build — coturn 4.6.1 did not, in a local test — so
 * that is measured on the real nodes, not assumed. Either way a long session needs a valid credential
 * for whatever it must set up AGAIN: an ICE restart that gathers afresh, a reconnect after a network
 * change. This asks the product's API again at `refreshAt` (80% of the lifetime by default) and hands
 * the fresh config to `onRefreshed`, where the product points its peer connections at it and — unless
 * the grant says `restartOnRenewal: false` — moves relayed ones onto it by restarting ICE.
 *
 * A static credential (no expiry) is never refreshed. A failed fetch is retried, with backoff, for as
 * long as the refresher runs.
 */

import { normalizeIceConfig } from './iceConfig.js';
import { defaultClock } from './supervisor.js';

const MIN_DELAY_MS = 30_000;
const RETRY_DELAY_MS = 15_000;
const MAX_RETRY_DELAY_MS = 120_000;

export class CredentialRefresher {
  /**
   * @param {object} options
   * @param {() => Promise<unknown>} options.fetch the product's endpoint; resolves to its raw response
   * @param {(config: ReturnType<typeof normalizeIceConfig>) => void} [options.onRefreshed]
   * @param {(error: unknown) => void} [options.onError]
   */
  constructor(options) {
    this.fetchFn = options.fetch;
    this.onRefreshed = options.onRefreshed ?? (() => {});
    this.onError = options.onError ?? (() => {});
    this.clock = options.clock ?? defaultClock;
    this.minDelayMs = options.minDelayMs ?? MIN_DELAY_MS;
    this.retryDelayMs = options.retryDelayMs ?? RETRY_DELAY_MS;
    this.current = null;
    this.timer = null;
    this.failures = 0;
    this.pending = null;
    this.stopped = true;
  }

  /** Begin with the config the connection was built from. */
  start(config) {
    this.stopped = false;
    this.current = config ?? null;
    this.schedule();

    return this;
  }

  /**
   * Fetch now. Concurrent callers share one request — a supervisor and the schedule asking at the
   * same moment is one round trip, not two.
   */
  refreshNow() {
    if (this.pending) return this.pending;

    this.pending = (async () => {
      try {
        const config = normalizeIceConfig(await this.fetchFn(), this.clock.now());
        if (this.stopped) return config;

        this.current = config;
        this.failures = 0;
        this.onRefreshed(config);

        return config;
      } catch (error) {
        this.failures += 1;
        this.onError(error);

        throw error;
      } finally {
        this.pending = null;
        if (!this.stopped) this.schedule();
      }
    })();

    return this.pending;
  }

  schedule() {
    this.clearTimer();
    if (this.stopped) return;

    let delay;
    if (this.failures > 0) {
      delay = Math.min(this.retryDelayMs * 2 ** (this.failures - 1), MAX_RETRY_DELAY_MS);
    } else if (this.current?.refreshAt != null) {
      delay = Math.max(this.minDelayMs, this.current.refreshAt - this.clock.now());
    } else {
      return;
    }

    this.timer = this.clock.setTimeout(() => {
      this.timer = null;
      this.refreshNow().catch(() => {});
    }, delay);
  }

  stop() {
    this.stopped = true;
    this.clearTimer();
  }

  clearTimer() {
    if (this.timer !== null) {
      this.clock.clearTimeout(this.timer);
      this.timer = null;
    }
  }
}
