<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;

/**
 * The Clients context's public interface: enrollment, and later the heartbeat
 * protocol and command delivery. Instances are referred to by their Fleet ID.
 */
final class ClientsApi
{
    private readonly EnrollmentTokens $enrollmentTokens;

    public function __construct(Database $database, Clock $clock)
    {
        $this->enrollmentTokens = new EnrollmentTokens($database, $clock);
    }

    /**
     * Issues a one-time enrollment token bound to the instance, replacing any
     * earlier one (design section 5.6 item 2).
     */
    public function issueEnrollmentToken(int $instanceId): IssuedEnrollmentToken
    {
        return $this->enrollmentTokens->issue($instanceId);
    }

    /**
     * @param int[] $instanceIds
     * @return array<int, EnrollmentState> keyed by instance ID, one entry per ID
     */
    public function enrollmentStates(array $instanceIds): array
    {
        $states = array_fill_keys($instanceIds, EnrollmentState::NotEnrolled);

        foreach ($this->enrollmentTokens->pendingAmong($instanceIds) as $instanceId) {
            $states[$instanceId] = EnrollmentState::WaitingForEnrollment;
        }

        return $states;
    }
}
