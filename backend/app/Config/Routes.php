<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/**
 * AICOUNTLY Remote API routes.
 *
 * Auto-routing is off (see Config\Routing), so a controller method is reachable
 * only if it is named here — which means an endpoint cannot be exposed by
 * accident, and the filter attached to it cannot be skipped by reaching the
 * method another way.
 *
 * Two groups exist:
 *   * the legacy auth surface (`/health`, `/global/*`, `/session`), kept
 *     byte-compatible with the API this replaces so sign-in keeps working;
 *   * the product API under `/v1/remote`.
 *
 * Filters, per route:
 *   `api-auth`         a signed-in AICOUNTLY user
 *   `api-auth:guest`   …or a guest holding a one-time session token
 *   `api-auth:optional` anonymous permitted
 *   `device-auth`      an enrolled device holding a short-lived credential it
 *                      obtained by proving possession of its private key; the
 *                      argument names the scope the route needs
 *   `remote-context`   verify and consume a launch context, when present
 *   `rate-limit:name,capacity,seconds`
 *
 * @var RouteCollection $routes
 */

// ---------------------------------------------------------------------------
// Health and portal auth relay (unchanged from the previous API)
// ---------------------------------------------------------------------------

$routes->get('/', 'HealthController::index');
$routes->get('health', 'HealthController::index');

// The relay is an allowlist inside the controller; the wildcard here only
// routes, it does not authorise. See PortalRelayController.
$routes->match(['get', 'post'], 'global/(:segment)', 'PortalRelayController::relay/$1', [
    'filter' => 'rate-limit:portal-relay,30,60',
]);
$routes->match(['get', 'post'], 'global/(:segment)/(:segment)', 'PortalRelayController::relay/$1/$2', [
    'filter' => 'rate-limit:portal-relay,30,60',
]);

$routes->get('session', 'PortalRelayController::whoami', ['filter' => 'api-auth']);

// ---------------------------------------------------------------------------
// Remote API v1
// ---------------------------------------------------------------------------

$routes->group('v1/remote', ['namespace' => 'App\Controllers\Api\V1'], static function (RouteCollection $routes): void {
    // --- Bootstrap and policy ---------------------------------------------
    $routes->get('bootstrap', 'BootstrapController::index', [
        'filter' => ['api-auth', 'remote-context'],
    ]);
    $routes->get('policy/effective', 'BootstrapController::effectivePolicy', ['filter' => 'api-auth']);

    // --- Sessions ----------------------------------------------------------
    $routes->post('sessions', 'SessionController::create', [
        'filter' => ['api-auth', 'remote-context', 'rate-limit:session-create,20,60'],
    ]);
    $routes->get('sessions/history', 'SessionController::history', ['filter' => 'api-auth']);

    $routes->get('sessions/(:segment)', 'SessionController::show/$1', ['filter' => 'api-auth:guest']);
    $routes->get('sessions/(:segment)/events', 'SessionController::events/$1', ['filter' => 'api-auth']);

    $routes->post('sessions/(:segment)/share-intent', 'SessionController::shareIntent/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/share-started', 'SessionController::shareStarted/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/share-stopped', 'SessionController::shareStopped/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/context-mismatch', 'SessionController::contextMismatch/$1', ['filter' => 'api-auth']);

    $routes->post('sessions/(:segment)/pause', 'SessionController::pause/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/resume', 'SessionController::resume/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/end', 'SessionController::end/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/feedback', 'SessionController::feedback/$1', ['filter' => 'api-auth']);

    // --- Participants ------------------------------------------------------
    $routes->post('sessions/(:segment)/join-request', 'ParticipantController::requestJoin/$1', [
        'filter' => ['api-auth', 'rate-limit:join,20,60'],
    ]);
    $routes->post('sessions/(:segment)/participants/(:segment)/approve', 'ParticipantController::approve/$1/$2', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/participants/(:segment)/deny', 'ParticipantController::deny/$1/$2', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/participants/(:segment)/joined', 'ParticipantController::markJoined/$1/$2', ['filter' => 'api-auth:guest']);
    $routes->post('sessions/(:segment)/participants/(:segment)/leave', 'ParticipantController::leave/$1/$2', ['filter' => 'api-auth:guest']);
    $routes->post('sessions/(:segment)/participants/(:segment)/presence', 'ParticipantController::presence/$1/$2', ['filter' => 'api-auth:guest']);

    // --- Signalling --------------------------------------------------------
    // Tight limit: a token is short-lived, but minting them in a loop is the
    // one way to put load on the signalling service from outside.
    $routes->post('sessions/(:segment)/signalling-token', 'SignallingController::token/$1', [
        'filter' => ['api-auth:guest', 'rate-limit:signalling-token,60,60'],
    ]);

    // --- Chat --------------------------------------------------------------
    $routes->get('sessions/(:segment)/messages', 'ChatController::index/$1', ['filter' => 'api-auth:guest']);
    $routes->post('sessions/(:segment)/messages', 'ChatController::create/$1', [
        'filter' => ['api-auth:guest', 'rate-limit:chat,120,60'],
    ]);

    // --- File transfer (§36) -----------------------------------------------
    // No file content passes through these — only the offer, the recipient's
    // decision and the outcome. The bytes go peer-to-peer over the data
    // channel, so there is nothing here to upload to.
    $routes->get('sessions/(:segment)/transfers', 'FileTransferController::index/$1', ['filter' => 'api-auth:guest']);
    $routes->post('sessions/(:segment)/transfers', 'FileTransferController::create/$1', [
        'filter' => ['api-auth:guest', 'rate-limit:file-offer,20,60'],
    ]);
    $routes->post('sessions/(:segment)/transfers/(:segment)/accept', 'FileTransferController::accept/$1/$2', ['filter' => 'api-auth:guest']);
    $routes->post('sessions/(:segment)/transfers/(:segment)/decline', 'FileTransferController::decline/$1/$2', ['filter' => 'api-auth:guest']);
    // Progress is throttled client-side; the limit is the backstop.
    $routes->post('sessions/(:segment)/transfers/(:segment)/progress', 'FileTransferController::progress/$1/$2', [
        'filter' => ['api-auth:guest', 'rate-limit:file-progress,120,60'],
    ]);
    $routes->post('sessions/(:segment)/transfers/(:segment)/complete', 'FileTransferController::complete/$1/$2', ['filter' => 'api-auth:guest']);
    $routes->post('sessions/(:segment)/transfers/(:segment)/abort', 'FileTransferController::abort/$1/$2', ['filter' => 'api-auth:guest']);

    // --- Remote control (docs/desktop/*) ------------------------------------
    // Attended control of a desktop agent. Every one of these resolves the
    // caller's policy for the *session's own* scope, so holding
    // `remote.control.request` at one organisation grants nothing in another's
    // session. Revoking needs no permission at all: stopping someone
    // controlling your computer must never depend on a grant.
    $routes->get('sessions/(:segment)/control', 'ControlController::show/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/control/request', 'ControlController::request/$1', [
        'filter' => ['api-auth', 'rate-limit:control-request,20,60'],
    ]);
    $routes->post('sessions/(:segment)/control/grant', 'ControlController::grant/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/control/deny', 'ControlController::deny/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/control/revoke', 'ControlController::revoke/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/control/clipboard', 'ControlController::clipboard/$1', ['filter' => 'api-auth']);

    // --- Devices ------------------------------------------------------------
    //
    // Two audiences, two filters, and the split is deliberate: `api-auth` is a
    // person, `device-auth` is a machine holding a credential it obtained by
    // proving possession of its enrolled private key. Nothing under
    // `devices/me` can reach a session, a company or another device.
    $routes->post('devices/enrol', 'DeviceController::enrol', [
        'filter' => ['api-auth', 'rate-limit:device-enrol,10,60'],
    ]);
    $routes->get('devices', 'DeviceController::index', ['filter' => 'api-auth']);

    // The agent's own routes come *before* `devices/(:segment)`, or `me` would
    // be routed as a device uuid and 404 on every call.
    $routes->get('devices/me', 'DeviceAgentController::me', ['filter' => 'device-auth']);
    $routes->post('devices/me/presence', 'DeviceAgentController::presence', [
        'filter' => ['device-auth:device.presence', 'rate-limit:device-presence,120,60'],
    ]);
    $routes->post('devices/me/presence-token', 'DeviceAgentController::presenceToken', [
        'filter' => ['device-auth:device.presence', 'rate-limit:device-presence-token,30,60'],
    ]);
    $routes->post('devices/me/sessions/(:segment)/join', 'DeviceAgentController::joinSession/$1', [
        'filter' => ['device-auth:device.session', 'rate-limit:device-join,30,60'],
    ]);
    $routes->post('devices/me/unattended/disable', 'DeviceAgentController::disableUnattended', [
        'filter' => 'device-auth:device.self',
    ]);

    // Unauthenticated by design — a nonce is worthless without the private key,
    // and the signature *is* the authentication. Both are rate-limited because
    // running them in a loop is the only load they can put on anything.
    $routes->post('devices/auth/challenge', 'DeviceAgentController::challenge', [
        'filter' => 'rate-limit:device-challenge,20,60',
    ]);
    $routes->post('devices/auth/verify', 'DeviceAgentController::verify', [
        'filter' => 'rate-limit:device-verify,20,60',
    ]);

    $routes->get('devices/(:segment)', 'DeviceController::show/$1', ['filter' => 'api-auth']);
    $routes->patch('devices/(:segment)', 'DeviceController::update/$1', ['filter' => 'api-auth']);
    $routes->post('devices/(:segment)/revoke', 'DeviceController::revoke/$1', ['filter' => 'api-auth']);
    $routes->post('devices/(:segment)/unattended/enable', 'DeviceController::enableUnattended/$1', ['filter' => 'api-auth']);
    $routes->post('devices/(:segment)/unattended/disable', 'DeviceController::disableUnattended/$1', ['filter' => 'api-auth']);
    $routes->post('devices/(:segment)/connect', 'DeviceController::connect/$1', [
        'filter' => ['api-auth', 'rate-limit:device-connect,20,60'],
    ]);
    $routes->post('devices/(:segment)/reboot', 'DeviceController::reboot/$1', [
        'filter' => ['api-auth', 'rate-limit:device-reboot,5,60'],
    ]);

    // --- Invitations -------------------------------------------------------
    $routes->get('sessions/(:segment)/invitations', 'InvitationController::index/$1', ['filter' => 'api-auth']);
    $routes->post('sessions/(:segment)/invitations', 'InvitationController::create/$1', [
        'filter' => ['api-auth', 'rate-limit:invitation,20,60'],
    ]);
    $routes->delete('sessions/(:segment)/invitations/(:segment)', 'InvitationController::revoke/$1/$2', ['filter' => 'api-auth']);

    // --- Joining -----------------------------------------------------------
    // Nine digits is a small space, so this is the tightest limit in the API.
    $routes->post('join/code', 'JoinController::byCode', [
        'filter' => ['api-auth', 'rate-limit:join-code,10,60'],
    ]);
    // Anonymous is permitted here and only here: an external guest has no
    // AICOUNTLY account, which is the point of a guest invitation.
    $routes->post('join/redeem', 'JoinController::redeem', [
        'filter' => ['api-auth:optional', 'rate-limit:join-redeem,10,60'],
    ]);

    // --- AICOUNTLY Support -------------------------------------------------
    $routes->post('support/requests', 'SupportController::create', [
        'filter' => ['api-auth', 'remote-context', 'rate-limit:support-request,10,60'],
    ]);
    $routes->get('support/requests', 'SupportController::index', ['filter' => 'api-auth']);
    $routes->get('support/requests/(:segment)', 'SupportController::show/$1', ['filter' => 'api-auth']);
    $routes->post('support/requests/(:segment)/accept', 'SupportController::accept/$1', ['filter' => 'api-auth']);
    $routes->post('support/requests/(:segment)/decline', 'SupportController::decline/$1', ['filter' => 'api-auth']);
    $routes->post('support/requests/(:segment)/cancel', 'SupportController::cancel/$1', ['filter' => 'api-auth']);

    // --- Company administration --------------------------------------------
    $routes->get('company/(:num)/policy', 'AdminController::showPolicy/$1', ['filter' => 'api-auth']);
    $routes->put('company/(:num)/policy', 'AdminController::updatePolicy/$1', ['filter' => 'api-auth']);
    $routes->get('company/(:num)/permissions', 'AdminController::listPermissions/$1', ['filter' => 'api-auth']);
    $routes->put('company/(:num)/permissions/(:segment)', 'AdminController::updateUserPermissions/$1/$2', ['filter' => 'api-auth']);
    $routes->get('company/(:num)/role-permissions', 'AdminController::listRolePermissions/$1', ['filter' => 'api-auth']);
    $routes->put('company/(:num)/role-permissions/(:segment)', 'AdminController::updateRolePermissions/$1/$2', ['filter' => 'api-auth']);
    $routes->get('company/(:num)/audit', 'AdminController::audit/$1', ['filter' => 'api-auth']);
});
