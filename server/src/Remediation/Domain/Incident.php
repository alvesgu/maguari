<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * The incident aggregate (design section 7.4): one ongoing problem with one
 * check of one instance and one subject. Immutable: each rule returns the
 * incident after it. A resolved incident never changes again.
 */
final class Incident
{
    /**
     * @param ?int $id null until stored
     * @param ?int $passingSince when the check started passing while open
     */
    public function __construct(
        public readonly ?int $id,
        public readonly IncidentKey $key,
        public readonly int $openedAt,
        public readonly ?int $passingSince = null,
        public readonly ?int $resolvedAt = null,
        public readonly ?Resolution $resolution = null,
    ) {
        if (($resolvedAt === null) !== ($resolution === null)) {
            throw new \InvalidArgumentException('A resolved incident has both a time and a resolution.');
        }
    }

    /**
     * Opened when the check fails and no incident is open for the key.
     */
    public static function open(IncidentKey $key, int $at): self
    {
        return new self(null, $key, $at);
    }

    public function isOpen(): bool
    {
        return $this->resolvedAt === null;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->key, $this->openedAt, $this->passingSince, $this->resolvedAt, $this->resolution);
    }

    /**
     * The check failed again: the wait for resolving starts over.
     */
    public function failedAgain(): self
    {
        $this->assertOpen();

        return new self($this->id, $this->key, $this->openedAt, null);
    }

    /**
     * The check passed. A pass while already passing keeps the earlier time.
     */
    public function passed(int $at): self
    {
        $this->assertOpen();

        return new self($this->id, $this->key, $this->openedAt, $this->passingSince ?? $at);
    }

    /**
     * Resolved once the check has passed for ResolutionRule::RESOLVE_AFTER_SECONDS.
     *
     * @return ?self null when not due yet
     */
    public function resolveIfDue(int $at): ?self
    {
        $this->assertOpen();

        return ResolutionRule::isDue($this->passingSince, $at) ? $this->resolve($at, Resolution::Passed) : null;
    }

    /**
     * Its subject is gone, so it can never pass: resolved at once.
     */
    public function withdraw(int $at): self
    {
        $this->assertOpen();

        return $this->resolve($at, Resolution::Withdrawn);
    }

    /**
     * Monitoring does not report the incident's check as failing (a lost
     * event). Resolved when Monitoring reports it passing for long enough,
     * or not at all; otherwise it waits, passing since Monitoring says.
     *
     * @param ?int $passingSince when Monitoring says the check started
     *        passing, null when it has no outcome for it
     */
    public function reconcile(?int $passingSince, int $at): self
    {
        $this->assertOpen();

        if ($passingSince === null || ResolutionRule::isDue($passingSince, $at)) {
            return $this->resolve($at, Resolution::Reconciled);
        }

        return new self($this->id, $this->key, $this->openedAt, $this->passingSince ?? $passingSince);
    }

    private function resolve(int $at, Resolution $resolution): self
    {
        return new self($this->id, $this->key, $this->openedAt, $this->passingSince, $at, $resolution);
    }

    private function assertOpen(): void
    {
        if (!$this->isOpen()) {
            throw new \LogicException(sprintf('Incident %d is resolved and never changes again.', $this->id ?? 0));
        }
    }
}
