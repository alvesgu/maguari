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
     * @param int $ladderStep the step of the escalation ladder it reached
     *        (Ladder), 0 before any
     * @param ?int $exhaustedAt when the ladder had nothing left to try
     * @param ?LadderHold $ladderHold why the ladder is waiting, for its page
     */
    public function __construct(
        public readonly ?int $id,
        public readonly IncidentKey $key,
        public readonly int $openedAt,
        public readonly ?int $passingSince = null,
        public readonly ?int $resolvedAt = null,
        public readonly ?Resolution $resolution = null,
        public readonly int $ladderStep = 0,
        public readonly ?int $exhaustedAt = null,
        public readonly ?LadderHold $ladderHold = null,
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
        return new self($id, $this->key, $this->openedAt, $this->passingSince, $this->resolvedAt, $this->resolution, $this->ladderStep, $this->exhaustedAt, $this->ladderHold);
    }

    /**
     * The check failed again: the wait for resolving starts over.
     */
    public function failedAgain(): self
    {
        $this->assertOpen();

        return $this->withPassingSince(null);
    }

    /**
     * The check passed. A pass while already passing keeps the earlier time.
     */
    public function passed(int $at): self
    {
        $this->assertOpen();

        return $this->withPassingSince($this->passingSince ?? $at);
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

        return $this->withPassingSince($this->passingSince ?? $passingSince);
    }

    /**
     * An action served this incident: it reached that step of the ladder.
     * Never goes back down.
     */
    public function reachedStep(int $step): self
    {
        $this->assertOpen();

        return new self($this->id, $this->key, $this->openedAt, $this->passingSince, null, null, max($this->ladderStep, $step), $this->exhaustedAt, null);
    }

    /**
     * The ladder has nothing left to try for this incident. It stays open
     * until its check passes.
     */
    public function exhausted(int $at): self
    {
        $this->assertOpen();

        return new self($this->id, $this->key, $this->openedAt, $this->passingSince, null, null, $this->ladderStep, $this->exhaustedAt ?? $at, null);
    }

    public function heldBy(?LadderHold $hold): self
    {
        $this->assertOpen();

        return new self($this->id, $this->key, $this->openedAt, $this->passingSince, null, null, $this->ladderStep, $this->exhaustedAt, $hold);
    }

    private function withPassingSince(?int $passingSince): self
    {
        return new self($this->id, $this->key, $this->openedAt, $passingSince, null, null, $this->ladderStep, $this->exhaustedAt, $this->ladderHold);
    }

    private function resolve(int $at, Resolution $resolution): self
    {
        return new self($this->id, $this->key, $this->openedAt, $this->passingSince, $at, $resolution, $this->ladderStep, $this->exhaustedAt, null);
    }

    private function assertOpen(): void
    {
        if (!$this->isOpen()) {
            throw new \LogicException(sprintf('Incident %d is resolved and never changes again.', $this->id ?? 0));
        }
    }
}
