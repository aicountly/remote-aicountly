<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * External meeting guests under a paid host's licensed meeting.
 *
 * A guest has no account here, so there is nothing to upgrade and nothing is: the guest's relay allocation is simply accounted to
 * the MEETING — its session reference — at the capacity class of the host's licence, while the host's licence permits it and the
 * meeting's limits hold. The same person joining another meeting, or calling from their own account, is classed on that context's
 * own entitlement; paid status never travels with them.
 *
 * Limits come from the host's signed entitlements (future central attributes), with conservative product defaults:
 *   meeting.guests_paid_quality   false = this plan gives guests no paid-quality service (absent: the policy default)
 *   meeting.max_guests            how many guests at once get it (absent: WEBRTC_LICENCE_DEFAULT_MAX_GUESTS, 5)
 *   meeting.max_participants      host + guests together (absent: no limit)
 * Positions are by join order among those currently in the meeting, so the earliest guests keep paid-quality service and a
 * later guest gains it when an earlier one leaves (the next credential renewal re-decides).
 */
final class MeetingGuests
{
    public function __construct(
        public readonly int $defaultMaxGuests = 5,
        public readonly bool $paidQualityByDefault = true,
    ) {
    }

    /** @param callable(string): (string|null) $read */
    public static function fromEnvironment(callable $read): self
    {
        $max = $read('WEBRTC_LICENCE_DEFAULT_MAX_GUESTS');

        return new self(
            is_numeric($max) ? max(0, min(500, (int) $max)) : 5,
            strtolower(trim((string) ($read('WEBRTC_LICENCE_GUESTS_PAID_QUALITY') ?: 'allow'))) !== 'deny',
        );
    }

    /**
     * @param Resolution $host                the meeting host's resolution IN THE MEETING'S COMPANY CONTEXT
     * @param int        $guestPosition       0-based place of this guest among the guests now in the meeting, by join order
     * @param int        $participantPosition 0-based place of this guest among everyone now in the meeting (host included)
     */
    public function decide(Resolution $host, int $guestPosition, int $participantPosition): Resolution
    {
        $free = static fn (string $reason): Resolution => new Resolution(LicenceState::Free, $reason, $host->evidence, $host->planCode, $host->revision, 'host_meeting', $host->entitlements);

        if ($host->state !== LicenceState::Paid) {
            return $free('host_not_paid');
        }

        $allowed = $host->entitlement('meeting.guests_paid_quality');
        if ($allowed === false || ($allowed === null && ! $this->paidQualityByDefault)) {
            return $free('guests_not_in_plan');
        }

        $maxGuests = $host->entitlement('meeting.max_guests');
        $maxGuests = is_int($maxGuests) ? max(0, $maxGuests) : $this->defaultMaxGuests;
        if ($guestPosition >= $maxGuests) {
            return $free('guest_limit');
        }

        $maxParticipants = $host->entitlement('meeting.max_participants');
        if (is_int($maxParticipants) && $participantPosition >= $maxParticipants) {
            return $free('participant_limit');
        }

        return new Resolution(LicenceState::Paid, 'host_meeting', $host->evidence, $host->planCode, $host->revision, 'host_meeting', $host->entitlements);
    }
}
