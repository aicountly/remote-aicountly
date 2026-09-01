<?php

declare(strict_types=1);

namespace Config;

use App\Domain\Audit\AuditService;
use App\Domain\Auth\IdentityResolver;
use App\Domain\Auth\PortalClient;
use App\Domain\Auth\SourceContextVerifier;
use App\Domain\Directory\PlatformDirectory;
use App\Domain\Policy\EffectivePolicyResolver;
use App\Domain\Session\ChatService;
use App\Domain\Session\FileTransferService;
use App\Domain\Session\InvitationService;
use App\Domain\Session\JoinService;
use App\Domain\Session\ParticipantService;
use App\Domain\Session\SessionService;
use App\Domain\Signalling\IceConfigService;
use App\Domain\Signalling\SignallingTokenService;
use App\Domain\Support\RequestContext;
use App\Domain\Support\SupportRequestService;
use CodeIgniter\Config\BaseService;

/**
 * The wiring.
 *
 * Every domain service is constructed here, once, so a controller never reaches
 * for `new` and a test can swap any of them by calling `Services::injectMock()`.
 * Keeping the graph in one file is also what makes the layering visible: a
 * controller depends on services, services depend on the connection and on each
 * other, and nothing depends on a controller.
 */
class Services extends BaseService
{
    public static function remoteConfig(bool $getShared = true): Remote
    {
        if ($getShared) {
            return static::getSharedInstance('remoteConfig');
        }

        return config(Remote::class);
    }

    public static function portalClient(bool $getShared = true): PortalClient
    {
        if ($getShared) {
            return static::getSharedInstance('portalClient');
        }

        return new PortalClient(static::remoteConfig());
    }

    public static function identityResolver(bool $getShared = true): IdentityResolver
    {
        if ($getShared) {
            return static::getSharedInstance('identityResolver');
        }

        return new IdentityResolver(static::portalClient(), db_connect(), cache());
    }

    public static function sourceContextVerifier(bool $getShared = true): SourceContextVerifier
    {
        if ($getShared) {
            return static::getSharedInstance('sourceContextVerifier');
        }

        return new SourceContextVerifier(static::remoteConfig(), db_connect());
    }

    public static function auditService(bool $getShared = true): AuditService
    {
        if ($getShared) {
            return static::getSharedInstance('auditService');
        }

        // The request is optional so the service still works from a CLI task,
        // where there is no IP and no user agent to record.
        $request = is_cli() ? null : service('request');

        return new AuditService(db_connect(), $request instanceof \CodeIgniter\HTTP\IncomingRequest ? $request : null);
    }

    public static function policyResolver(bool $getShared = true): EffectivePolicyResolver
    {
        if ($getShared) {
            return static::getSharedInstance('policyResolver');
        }

        return new EffectivePolicyResolver(db_connect(), static::remoteConfig());
    }

    public static function participantService(bool $getShared = true): ParticipantService
    {
        if ($getShared) {
            return static::getSharedInstance('participantService');
        }

        return new ParticipantService(db_connect(), static::auditService());
    }

    public static function sessionService(bool $getShared = true): SessionService
    {
        if ($getShared) {
            return static::getSharedInstance('sessionService');
        }

        return new SessionService(
            db_connect(),
            static::policyResolver(),
            static::auditService(),
            static::remoteConfig(),
            static::participantService(),
        );
    }

    public static function invitationService(bool $getShared = true): InvitationService
    {
        if ($getShared) {
            return static::getSharedInstance('invitationService');
        }

        return new InvitationService(db_connect(), static::auditService(), static::remoteConfig());
    }

    public static function joinService(bool $getShared = true): JoinService
    {
        if ($getShared) {
            return static::getSharedInstance('joinService');
        }

        return new JoinService(
            db_connect(),
            static::sessionService(),
            static::participantService(),
            static::invitationService(),
            static::policyResolver(),
            static::auditService(),
            static::remoteConfig(),
        );
    }

    public static function supportRequestService(bool $getShared = true): SupportRequestService
    {
        if ($getShared) {
            return static::getSharedInstance('supportRequestService');
        }

        return new SupportRequestService(
            db_connect(),
            static::sessionService(),
            static::participantService(),
            static::policyResolver(),
            static::auditService(),
            static::remoteConfig(),
        );
    }

    public static function chatService(bool $getShared = true): ChatService
    {
        if ($getShared) {
            return static::getSharedInstance('chatService');
        }

        return new ChatService(db_connect(), static::auditService());
    }

    public static function fileTransferService(bool $getShared = true): FileTransferService
    {
        if ($getShared) {
            return static::getSharedInstance('fileTransferService');
        }

        return new FileTransferService(
            db_connect(),
            static::auditService(),
            static::participantService(),
            static::remoteConfig(),
        );
    }

    public static function signallingTokenService(bool $getShared = true): SignallingTokenService
    {
        if ($getShared) {
            return static::getSharedInstance('signallingTokenService');
        }

        return new SignallingTokenService(static::remoteConfig());
    }

    public static function iceConfigService(bool $getShared = true): IceConfigService
    {
        if ($getShared) {
            return static::getSharedInstance('iceConfigService');
        }

        return new IceConfigService(static::remoteConfig());
    }

    public static function platformDirectory(bool $getShared = true): PlatformDirectory
    {
        if ($getShared) {
            return static::getSharedInstance('platformDirectory');
        }

        return new PlatformDirectory(db_connect(), static::remoteConfig());
    }

    /**
     * Who the current request is from. Populated by the auth filter and read by
     * controllers — never set from anywhere else.
     */
    public static function requestContext(bool $getShared = true): RequestContext
    {
        if ($getShared) {
            return static::getSharedInstance('requestContext');
        }

        return new RequestContext();
    }
}
