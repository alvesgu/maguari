<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Application;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\CertificateHostname;
use Maguari\Server\Monitoring\Exception\InvalidCertificateHostname;
use Maguari\Server\Monitoring\Infrastructure\CertificateHostnameRepository;
use Maguari\Server\Monitoring\Infrastructure\MetricRunRepository;
use Maguari\Shared\Metric;

/**
 * The hostnames each instance's remote certificate check connects to, which
 * administrators set on the instance page (design section 8.1 item 4).
 */
final class CertificateHostnames
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly CertificateHostnameRepository $hostnames,
        private readonly MetricRunRepository $metricRuns,
    ) {
    }

    /**
     * @return list<CertificateHostname>
     */
    public function forInstance(int $instanceId): array
    {
        return $this->hostnames->forInstance($instanceId);
    }

    /**
     * The count and the duplicate are checked in the same transaction as the
     * insert, so two submissions cannot both pass them.
     *
     * @throws InvalidCertificateHostname
     */
    public function add(int $instanceId, string $input): CertificateHostname
    {
        $hostname = CertificateHostname::normalize($input);
        $at = $this->clock->now();

        return $this->database->transaction(function () use ($instanceId, $hostname, $at): CertificateHostname {
            $existing = array_map(static fn (CertificateHostname $known): string => $known->hostname, $this->hostnames->forInstance($instanceId));

            if (in_array($hostname, $existing, true)) {
                throw new InvalidCertificateHostname(sprintf('%s is already checked for this instance.', $hostname));
            }

            if (count($existing) >= CertificateHostname::MAX_PER_INSTANCE) {
                throw new InvalidCertificateHostname(sprintf('An instance can have at most %d hostnames checked remotely.', CertificateHostname::MAX_PER_INSTANCE));
            }

            return new CertificateHostname($this->hostnames->add($instanceId, $hostname, $at), $instanceId, $hostname, $at);
        });
    }

    /**
     * @return bool whether it existed
     */
    public function remove(int $instanceId, int $hostnameId): bool
    {
        return $this->hostnames->remove($instanceId, $hostnameId);
    }

    /**
     * The domains of the certificates the instance reported recently that
     * could be checked remotely and are not yet: a site served from the
     * instance that holds its certificate is the usual case. Wildcards are
     * left out. Nothing is added automatically.
     *
     * @return list<string> in domain order
     */
    public function suggestions(int $instanceId): array
    {
        $at = $this->clock->now();
        $configured = array_map(static fn (CertificateHostname $known): string => $known->hostname, $this->hostnames->forInstance($instanceId));
        $suggestions = [];

        foreach ($this->metricRuns->currentOfKind($instanceId, Metric::CERTIFICATE_EXPIRES_AT) as $domain => $run) {
            $domain = (string) $domain;

            if ($run->isRecent($at) && CertificateHostname::isValid($domain) && !in_array($domain, $configured, true)) {
                $suggestions[] = $domain;
            }
        }

        return $suggestions;
    }
}
