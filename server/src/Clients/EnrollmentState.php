<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

/**
 * Where an instance is in enrollment (design section 5.6).
 */
enum EnrollmentState
{
    /** No client and no valid enrollment token. */
    case NotEnrolled;
    /** A token was issued and has not expired or been used yet. */
    case WaitingForEnrollment;
    /** A client completed enrollment. A newer pending token does not change this. */
    case Enrolled;

    public function label(): string
    {
        return match ($this) {
            self::NotEnrolled => 'Not enrolled',
            self::WaitingForEnrollment => 'Waiting for enrollment',
            self::Enrolled => 'Enrolled',
        };
    }
}
