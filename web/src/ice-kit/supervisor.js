/**
 * Watches one peer connection and decides when, and how often, to restart ICE — and when to stop.
 *
 * It decides *when*; the product decides *how*. Restarting ICE is a negotiation, and the products
 * negotiate differently: Connect uses perfect negotiation, so `pc.restartIce()` is enough; Remote has
 * one fixed offerer per pair, so the other side asks it to restart. The supervisor calls the `restart`
 * function it was given and watches what happens next.
 *
 * The policy, driven only by the connection's own state — never a timer standing in for it:
 *
 *   * `disconnected` often heals by itself (a Wi-Fi blip, a handover), so it gets a short grace
 *     period before anything is done;
 *   * `failed` will not heal, so it is restarted at once;
 *   * each attempt gets longer to work than the last; credentials are refreshed before an attempt
 *     when they are close to expiry or when an earlier attempt did not work;
 *   * with the browser offline, attempts wait for the network rather than being spent on nothing;
 *   * after `maxAttempts`, or once `recoveryWindowMs` has passed, it gives up and says so — a frozen
 *     picture with "reconnecting…" under it forever is worse than an honest ending.
 *
 * States: `new` → `connecting` → `connected`, then `unstable` (disconnected, in its grace period),
 * `recovering` (restarting) and back, or `failed` (given up). `closed` once disposed.
 */

import { isExpired } from './iceConfig.js';

const DEFAULTS = {
  disconnectedGraceMs: 2500,
  attemptTimeoutMs: 8000,
  maxAttempts: 3,
  recoveryWindowMs: 30000,
};

export const defaultClock = {
  setTimeout: (fn, ms) => setTimeout(fn, ms),
  clearTimeout: (id) => clearTimeout(id),
  now: () => Date.now(),
};

export class ConnectionSupervisor {
  /**
   * @param {object} options
   * @param {RTCPeerConnection} options.pc
   * @param {(info: {attempt: number, reason: string}) => (void|Promise<void>)} options.restart
   * @param {() => Promise<object|null>} [options.refreshCredentials] fetch and apply fresh credentials; resolves to the new config
   * @param {() => object|null} [options.getConfig] the normalised ICE config in use, to judge expiry
   * @param {(state: string, info: object) => void} [options.onStateChange]
   * @param {(reason: string) => void} [options.onGiveUp]
   * @param {(info: {attempts: number, ms: number}) => void} [options.onRecovered]
   * @param {{addEventListener: Function, removeEventListener: Function, navigator?: {onLine?: boolean}}|null} [options.network] where `online`/`offline` fire; window by default
   */
  constructor(options) {
    this.pc = options.pc;
    this.restartFn = options.restart;
    this.refreshCredentials = options.refreshCredentials ?? null;
    this.getConfig = options.getConfig ?? (() => null);
    this.onStateChange = options.onStateChange ?? (() => {});
    this.onGiveUp = options.onGiveUp ?? (() => {});
    this.onRecovered = options.onRecovered ?? (() => {});
    this.clock = options.clock ?? defaultClock;
    this.settings = { ...DEFAULTS, ...pick(options, Object.keys(DEFAULTS)) };
    this.network = options.network === undefined ? (typeof window !== 'undefined' ? window : null) : options.network;

    this.state = 'new';
    this.attempts = 0;
    this.restartsTotal = 0;
    this.recovering = false;
    this.recoveryStartedAt = 0;
    this.waitingForNetwork = false;
    /** An attempt is between deciding to restart and having restarted. */
    this.inFlight = false;
    this.disposed = false;
    this.timers = { grace: null, attempt: null, deadline: null };

    this.evaluate = this.evaluate.bind(this);
    this.onOnline = () => this.networkChanged(true);
    this.onOffline = () => this.networkChanged(false);

    this.pc.addEventListener?.('connectionstatechange', this.evaluate);
    this.pc.addEventListener?.('iceconnectionstatechange', this.evaluate);
    this.network?.addEventListener?.('online', this.onOnline);
    this.network?.addEventListener?.('offline', this.onOffline);

    this.evaluate();
  }

  /** The browser's view, combined: the ICE agent often knows first, connectionState is the summary. */
  connectionState() {
    const connection = this.pc.connectionState;
    const ice = this.pc.iceConnectionState;

    if (connection === 'closed' || ice === 'closed') return 'closed';
    if (connection === 'failed' || ice === 'failed') return 'failed';
    if (connection === 'connected' || ice === 'connected' || ice === 'completed') {
      return ice === 'disconnected' ? 'disconnected' : 'connected';
    }
    if (connection === 'disconnected' || ice === 'disconnected') return 'disconnected';
    if (connection === 'connecting' || ice === 'checking') return 'connecting';

    return 'new';
  }

  evaluate() {
    if (this.disposed) return;

    const state = this.connectionState();

    switch (state) {
      case 'connected': {
        this.clear('grace');
        this.clear('attempt');
        this.clear('deadline');
        if (this.recovering) {
          this.onRecovered({ attempts: this.attempts, ms: this.clock.now() - this.recoveryStartedAt });
        }
        this.recovering = false;
        this.waitingForNetwork = false;
        this.attempts = 0;
        this.setState('connected');
        break;
      }

      case 'disconnected': {
        if (this.recovering || this.timers.grace !== null) break;
        this.setState('unstable');
        this.timers.grace = this.clock.setTimeout(() => {
          this.timers.grace = null;
          if (this.connectionState() !== 'connected') this.beginRecovery('disconnected');
        }, this.settings.disconnectedGraceMs);
        break;
      }

      case 'failed': {
        this.clear('grace');
        if (!this.recovering) {
          this.beginRecovery('failed');
        } else if (!this.waitingForNetwork && !this.inFlight) {
          // The last attempt did not work; do not wait out its timer.
          this.clear('attempt');
          void this.attempt('failed');
        }
        break;
      }

      case 'closed':
        this.dispose();
        break;

      default:
        if (!this.recovering && this.state === 'new') this.setState(state);
        break;
    }
  }

  /**
   * Restart now, outside the failure path — the peer asked, or fresh credentials have to replace a
   * relay allocation before the old ones expire. Not counted against the recovery budget.
   */
  async requestRestart(reason) {
    if (this.disposed || this.recovering) return;
    this.restartsTotal += 1;
    try {
      await this.restartFn({ attempt: 0, reason });
    } catch {
      // A requested restart that could not start leaves the connection as it was.
    }
  }

  /**
   * Start recovery again after giving up — a person pressed "Reconnect". A fresh budget of attempts
   * and a fresh window; refused while recovery is already running.
   */
  retry(reason = 'manual') {
    if (this.disposed || this.recovering) return;
    this.beginRecovery(reason);
  }

  beginRecovery(reason) {
    if (this.disposed || this.recovering) return;

    this.recovering = true;
    this.recoveryStartedAt = this.clock.now();
    this.attempts = 0;
    this.timers.deadline = this.clock.setTimeout(() => {
      this.timers.deadline = null;
      if (this.connectionState() !== 'connected') this.giveUp('timeout');
    }, this.settings.recoveryWindowMs);

    void this.attempt(reason);
  }

  async attempt(reason) {
    if (this.disposed || !this.recovering || this.inFlight) return;

    if (this.isOffline()) {
      // Nothing can be gathered without a network; wait for it instead of spending an attempt.
      this.waitingForNetwork = true;
      this.setState('recovering', { reason: 'offline' });

      return;
    }
    this.waitingForNetwork = false;

    if (this.attempts >= this.settings.maxAttempts) {
      this.giveUp('exhausted');

      return;
    }

    this.inFlight = true;
    this.attempts += 1;
    this.restartsTotal += 1;
    const attempt = this.attempts;
    this.setState('recovering', { reason, attempt });

    try {
      await this.runAttempt(attempt, reason);
    } finally {
      this.inFlight = false;
    }
    if (this.disposed || !this.recovering) return;

    this.clear('attempt');
    this.timers.attempt = this.clock.setTimeout(() => {
      this.timers.attempt = null;
      if (this.connectionState() !== 'connected') void this.attempt('attempt_timeout');
    }, this.settings.attemptTimeoutMs * attempt);
  }

  async runAttempt(attempt, reason) {
    // A second attempt means the first did not work — credentials are a likely reason, and fetching
    // new ones is cheap. Near expiry they are needed anyway.
    const config = this.getConfig();
    if (this.refreshCredentials && (attempt > 1 || isExpired(config, this.clock.now(), 60_000))) {
      try {
        await this.refreshCredentials();
      } catch {
        // Restart with what we have; the next attempt will ask again.
      }
      if (this.disposed || !this.recovering) return;
    }

    try {
      await this.restartFn({ attempt, reason });
    } catch {
      // Treated like an attempt that did not connect: the attempt timer moves on.
    }
  }

  networkChanged(online) {
    if (this.disposed) return;

    if (!online) {
      if (this.state === 'connected') return;
      this.setState(this.recovering ? 'recovering' : 'unstable', { reason: 'offline' });

      return;
    }

    // Back online: a path that died with the old network will not come back by itself.
    if (this.recovering) {
      this.clear('attempt');
      void this.attempt('network_changed');
    } else if (this.connectionState() !== 'connected' && this.state !== 'new') {
      this.clear('grace');
      this.beginRecovery('network_changed');
    }
  }

  giveUp(reason) {
    if (this.disposed) return;
    this.clear('grace');
    this.clear('attempt');
    this.clear('deadline');
    this.recovering = false;
    this.setState('failed', { reason });
    this.onGiveUp(reason);
  }

  isOffline() {
    const nav = this.network?.navigator ?? (typeof navigator !== 'undefined' ? navigator : null);

    return nav?.onLine === false;
  }

  setState(state, info = {}) {
    const changed = state !== this.state || info.attempt !== undefined;
    this.state = state;
    if (changed) this.onStateChange(state, { attempts: this.attempts, ...info });
  }

  clear(name) {
    if (this.timers[name] !== null) {
      this.clock.clearTimeout(this.timers[name]);
      this.timers[name] = null;
    }
  }

  dispose() {
    if (this.disposed) return;
    this.disposed = true;
    this.clear('grace');
    this.clear('attempt');
    this.clear('deadline');
    this.pc.removeEventListener?.('connectionstatechange', this.evaluate);
    this.pc.removeEventListener?.('iceconnectionstatechange', this.evaluate);
    this.network?.removeEventListener?.('online', this.onOnline);
    this.network?.removeEventListener?.('offline', this.onOffline);
    this.state = 'closed';
  }
}

function pick(source, keys) {
  const out = {};
  for (const key of keys) {
    if (typeof source[key] === 'number' && Number.isFinite(source[key])) out[key] = source[key];
  }

  return out;
}
