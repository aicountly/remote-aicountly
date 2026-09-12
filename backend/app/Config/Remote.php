<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * AICOUNTLY Remote — product configuration.
 *
 * Everything here is read from the server `.env` at runtime. Nothing in this
 * file is a secret by itself; the secrets it names (`remote.contextSecret`,
 * `remote.signallingSecret`, the TURN credential) live only in that `.env`,
 * which is created by hand on the server and never committed or deployed.
 */
class Remote extends BaseConfig
{
    // -----------------------------------------------------------------------
    // Identity of this deployment
    // -----------------------------------------------------------------------

    /** Public origin of the Remote app, used to build invitation links. */
    public string $appUrl = 'https://remote.aicountly.com';

    /** Public WebSocket URL of the signalling service. Handed to the browser. */
    public string $signalUrl = 'wss://remote.aicountly.com/signal';

    // -----------------------------------------------------------------------
    // Source-context tokens (§6C — launching Remote from another AICOUNTLY SaaS)
    // -----------------------------------------------------------------------

    /**
     * Shared secret the issuing AICOUNTLY product signs its context token with.
     *
     * Empty disables context-token acceptance entirely, which is the correct
     * failure mode: an unconfigured deployment must not accept unverifiable
     * company context. Never trust `?company_id=` — see §6.
     */
    public string $contextSecret = '';

    /** Expected `iss` claim. */
    public string $contextIssuer = 'https://my.aicountly.com';

    /** Expected `aud` claim — this product. */
    public string $contextAudience = 'aicountly-remote';

    /** Hard ceiling on context-token age, whatever the token itself claims. */
    public int $contextMaxAgeSeconds = 300;

    /**
     * Products allowed to launch Remote with signed context (§6C allowlist).
     * A token naming anything else is rejected even when its signature is good.
     */
    public array $sourceProductAllowlist = [
        'BOOKS',
        'HRMS',
        'AUDITOR',
        'INVENTORY',
        'ADVISOR',
        'MANAGE',
        'PULSE',
        'CONNECT',
    ];

    // -----------------------------------------------------------------------
    // Signalling tokens (§19)
    // -----------------------------------------------------------------------

    /**
     * HMAC secret shared with the Node signalling service. The signalling
     * service verifies, it never issues: authorisation originates here.
     */
    public string $signallingSecret = '';

    /** Signalling tokens are deliberately short-lived; the client re-mints. */
    public int $signallingTokenTtlSeconds = 120;

    // -----------------------------------------------------------------------
    // Desktop agents (docs/desktop/*)
    // -----------------------------------------------------------------------

    /**
     * How long a device authentication challenge stays redeemable.
     *
     * Short on purpose: it is a nonce the agent signs immediately. Anything
     * longer only widens the window in which a captured signature would still
     * be worth replaying — and it is single-use anyway, so a legitimate agent
     * never needs the extra time.
     */
    public int $deviceChallengeTtlSeconds = 120;

    /**
     * How long a device access credential lasts. The agent re-authenticates
     * with its key; a machine credential that never expires is one that leaks.
     */
    public int $deviceTokenTtlSeconds = 900;

    /**
     * Lifetime of a device's presence signalling token.
     *
     * Longer than a session token because the agent holds one connection open
     * for hours rather than minutes, and shorter than the signalling service's
     * own hard ceiling so a token that outlives its authorisation is refused by
     * the relay as well as by this API.
     */
    public int $devicePresenceTokenTtlSeconds = 540;

    /**
     * After how long without a heartbeat a device counts as offline.
     *
     * A crashed agent gets no chance to write OFFLINE on its way out, so the
     * timestamp decides rather than the stored state.
     */
    public int $devicePresenceStaleSeconds = 180;

    /** Ceiling on one clipboard payload, in bytes (§14 — text only in V1). */
    public int $clipboardMaxBytes = 64 * 1024;

    /**
     * The desktop agent build this deployment expects.
     *
     * Advisory: it is what the web console shows beside an out-of-date agent,
     * and what the update endpoint compares against. It never blocks a session
     * — refusing to help somebody because their agent is a version behind is
     * not a security control, it is an outage.
     */
    public string $desktopMinimumAgentVersion = '1.0.0';

    /** Where the agent's signed update manifest lives. Empty disables updates. */
    public string $desktopUpdateFeedUrl = '';

    // -----------------------------------------------------------------------
    // ICE (§20). Never hardcode a credential — these come from .env.
    // -----------------------------------------------------------------------

    /** @var list<string> */
    public array $stunUrls = ['stun:stun.l.google.com:19302'];

    /** @var list<string> */
    public array $turnUrls = [];

    public string $turnUsername = '';
    public string $turnCredential = '';

    /**
     * Static-auth-secret TURN (coturn `use-auth-secret`). When set, the API
     * mints an ephemeral username/credential pair per session instead of
     * handing out a long-lived one. Preferred in production.
     */
    public string $turnStaticAuthSecret = '';
    public int $turnCredentialTtlSeconds = 3600;

    // -----------------------------------------------------------------------
    // Session and invitation lifetimes
    // -----------------------------------------------------------------------

    public int $sessionDefaultMinutes = 60;
    public int $inviteDefaultMinutes = 10;

    /** How long an unanswered support request stays in the queue. */
    public int $supportRequestExpiryMinutes = 30;

    /** Join codes are 9 digits, displayed in three groups (§6E). */
    public int $joinCodeLength = 9;

    // -----------------------------------------------------------------------
    // Feature flags (§67). A flag can only ever *remove* capability: policy is
    // still evaluated after the flag, never instead of it.
    // -----------------------------------------------------------------------

    public bool $featureFileTransfer = true;
    public bool $featureRecording = false;
    public bool $featureExternalGuest = true;
    public bool $featureSafeShare = true;
    public bool $featureMicrophone = true;
    public bool $featureMultiViewer = false;

    /**
     * Desktop agents, as a global flag.
     *
     * Like every other feature flag it can only ever *remove* capability: with
     * it off, no device can enrol and no device capability resolves true, even
     * for an organisation whose policy and plan both permit it (§67).
     */
    public bool $featureDesktopAgent = true;

    /** Conservative V1 ceiling for browser-to-browser file transfer (§36). */
    public int $fileTransferMaxBytes = 25 * 1024 * 1024;

    // -----------------------------------------------------------------------
    // Platform integration
    // -----------------------------------------------------------------------

    /** AICOUNTLY portal auth API. Serves production *and* sandbox. */
    public string $portalAuthBase = 'https://my.aicountly.com';

    /**
     * Optional platform directory API (company masters + membership). When
     * unset, Remote relies on its local projection, which is populated from
     * verified context tokens and by an administrator.
     */
    public string $directoryBase = '';
    public string $directoryToken = '';

    /** Seconds a directory answer stays cached in the local projection. */
    public int $directoryCacheSeconds = 900;

    /**
     * User ids allowed to act as AICOUNTLY support technicians. Empty means
     * support acceptance is driven purely by the `remote.support.accept`
     * permission, which is the normal production arrangement.
     */
    public array $supportTechnicianUserIds = [];

    /** Origins allowed to call this API cross-origin (§29). */
    public array $corsAllowedOrigins = [];

    public function __construct()
    {
        parent::__construct();

        $this->appUrl    = rtrim($this->envString('remote.appUrl', $this->appUrl), '/');
        $this->signalUrl = rtrim($this->envString('remote.signalUrl', $this->signalUrl), '/');

        $this->contextSecret        = $this->envString('remote.contextSecret', '');
        $this->contextIssuer        = $this->envString('remote.contextIssuer', $this->contextIssuer);
        $this->contextAudience      = $this->envString('remote.contextAudience', $this->contextAudience);
        $this->contextMaxAgeSeconds = $this->envInt('remote.contextMaxAgeSeconds', $this->contextMaxAgeSeconds);

        $allowlist = $this->envList('remote.sourceProductAllowlist');
        if ($allowlist !== []) {
            $this->sourceProductAllowlist = array_map('strtoupper', $allowlist);
        }

        $this->signallingSecret          = $this->envString('remote.signallingSecret', '');
        $this->signallingTokenTtlSeconds = $this->envInt('remote.signallingTokenTtl', $this->signallingTokenTtlSeconds);

        $this->deviceChallengeTtlSeconds = $this->envInt('remote.deviceChallengeTtl', $this->deviceChallengeTtlSeconds);
        $this->deviceTokenTtlSeconds     = $this->envInt('remote.deviceTokenTtl', $this->deviceTokenTtlSeconds);
        $this->devicePresenceTokenTtlSeconds = $this->envInt('remote.devicePresenceTokenTtl', $this->devicePresenceTokenTtlSeconds);
        $this->devicePresenceStaleSeconds    = $this->envInt('remote.devicePresenceStaleSeconds', $this->devicePresenceStaleSeconds);
        $this->clipboardMaxBytes             = $this->envInt('remote.clipboardMaxBytes', $this->clipboardMaxBytes);
        $this->desktopMinimumAgentVersion    = $this->envString('remote.desktopMinimumAgentVersion', $this->desktopMinimumAgentVersion);
        $this->desktopUpdateFeedUrl          = rtrim($this->envString('remote.desktopUpdateFeedUrl', ''), '/');

        $stun = $this->envList('remote.stunUrls');
        if ($stun !== []) {
            $this->stunUrls = $stun;
        }
        $this->turnUrls             = $this->envList('remote.turnUrls');
        $this->turnUsername         = $this->envString('remote.turnUsername', '');
        $this->turnCredential       = $this->envString('remote.turnCredential', '');
        $this->turnStaticAuthSecret = $this->envString('remote.turnStaticAuthSecret', '');
        $this->turnCredentialTtlSeconds = $this->envInt('remote.turnCredentialTtl', $this->turnCredentialTtlSeconds);

        $this->sessionDefaultMinutes       = $this->envInt('remote.sessionDefaultMinutes', $this->sessionDefaultMinutes);
        $this->inviteDefaultMinutes        = $this->envInt('remote.inviteDefaultMinutes', $this->inviteDefaultMinutes);
        $this->supportRequestExpiryMinutes = $this->envInt('remote.supportRequestExpiryMinutes', $this->supportRequestExpiryMinutes);

        $this->featureFileTransfer = $this->envBool('remote.allowFileTransfer', $this->featureFileTransfer);
        $this->featureRecording    = $this->envBool('remote.allowRecording', $this->featureRecording);
        $this->featureExternalGuest = $this->envBool('remote.allowExternalGuest', $this->featureExternalGuest);
        $this->featureSafeShare    = $this->envBool('remote.allowSafeShare', $this->featureSafeShare);
        $this->featureMicrophone   = $this->envBool('remote.allowMicrophone', $this->featureMicrophone);
        $this->featureMultiViewer  = $this->envBool('remote.allowMultiViewer', $this->featureMultiViewer);
        $this->featureDesktopAgent = $this->envBool('remote.allowDesktopAgent', $this->featureDesktopAgent);
        $this->fileTransferMaxBytes = $this->envInt('remote.fileTransferMaxBytes', $this->fileTransferMaxBytes);

        $this->portalAuthBase  = rtrim($this->envString('remote.portalAuthBase', $this->portalAuthBase), '/');
        $this->directoryBase   = rtrim($this->envString('remote.directoryBase', ''), '/');
        $this->directoryToken  = $this->envString('remote.directoryToken', '');
        $this->directoryCacheSeconds = $this->envInt('remote.directoryCacheSeconds', $this->directoryCacheSeconds);

        $this->supportTechnicianUserIds = array_values(array_filter(
            array_map('intval', $this->envList('remote.supportTechnicianUserIds')),
        ));

        $this->corsAllowedOrigins = $this->envList('remote.corsAllowedOrigins');
    }

    private function envString(string $key, string $default): string
    {
        $value = env($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function envInt(string $key, int $default): int
    {
        $value = env($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function envBool(string $key, bool $default): bool
    {
        $value = env($key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    private function envList(string $key): array
    {
        $value = env($key);
        if (! is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
    }
}
