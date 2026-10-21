<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * Each check's name for people, for pages that list results of several checks.
 */
final class CheckLabels
{
    private const LABELS = [
        HeartbeatAgeRule::CHECK_NAME => 'Heartbeat age',
        DiskSizeRule::CHECK_NAME => 'Disk size',
        CertificateExpiryRule::LOCAL_CHECK_NAME => 'Certificate on the instance',
        CertificateExpiryRule::REMOTE_CHECK_NAME => 'Served certificate',
        HttpCheck::CHECK_NAME => 'Web app',
    ];

    /**
     * The check's own name when it has no label, so a newer check still shows.
     */
    public static function of(string $checkName): string
    {
        return self::LABELS[$checkName] ?? $checkName;
    }
}
