<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

use Maguari\Server\Access\Exception\InvalidSetupInput;
use Maguari\Server\Access\Exception\SetupNotAllowed;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;

final class AccessApi
{
    public const PASSWORD_MIN_LENGTH = 12;
    public const PASSWORD_MAX_LENGTH = 1024;
    public const NAME_MAX_LENGTH = 100;

    private readonly AdministratorRepository $administrators;
    private readonly SetupTokens $setupTokens;
    private readonly LoginThrottle $loginThrottle;
    private readonly PasswordHasher $passwordHasher;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly SeedConfigReader $seedConfigReader = new SeedConfigReader(),
    ) {
        $this->administrators = new AdministratorRepository($database);
        $this->setupTokens = new SetupTokens($database, $clock);
        $this->loginThrottle = new LoginThrottle($database, $clock);
        $this->passwordHasher = new PasswordHasher();
    }

    public function readSeedConfig(): ?SeedConfig
    {
        return $this->seedConfigReader->read();
    }

    /**
     * Setup is complete once the first administrator exists.
     */
    public function isSetupComplete(): bool
    {
        return $this->administrators->exists();
    }

    /**
     * @throws SetupNotAllowed when setup is already complete
     */
    public function issueSetupToken(): IssuedSetupToken
    {
        if ($this->isSetupComplete()) {
            throw new SetupNotAllowed('Setup is already complete: an administrator exists.');
        }

        return $this->setupTokens->issue();
    }

    public function isSetupTokenValid(string $token): bool
    {
        return !$this->isSetupComplete() && $this->setupTokens->isValid($token);
    }

    /**
     * Creates the first administrator and consumes the setup token.
     *
     * @throws SetupNotAllowed when setup is complete or the token is not valid
     * @throws InvalidSetupInput when a field is invalid
     */
    public function completeSetup(
        string $token,
        string $name,
        string $email,
        string $password,
        string $passwordConfirmation,
    ): Administrator {
        if (!$this->isSetupTokenValid($token)) {
            throw new SetupNotAllowed('The setup token is missing, unknown or expired.');
        }

        $name = trim($name);
        $email = strtolower(trim($email));
        $errors = $this->validateSetupInput($name, $email, $password, $passwordConfirmation);

        if ($errors !== []) {
            throw new InvalidSetupInput($errors);
        }

        // Hashing is slow, so it happens before the write lock is taken.
        $passwordHash = $this->passwordHasher->hash($password);

        return $this->database->transaction(function () use ($token, $name, $email, $passwordHash): Administrator {
            // Checked again under the write lock, so two submissions cannot both succeed.
            if ($this->administrators->exists() || !$this->setupTokens->isValid($token)) {
                throw new SetupNotAllowed('The setup token is missing, unknown or expired.');
            }

            $administrator = $this->administrators->create($name, $email, $passwordHash, $this->clock->now());
            $this->setupTokens->consume($token);

            return $administrator;
        });
    }

    public function authenticate(string $email, string $password): ?Administrator
    {
        $found = $this->administrators->findWithPasswordHash(strtolower(trim($email)));

        if ($found === null) {
            $this->passwordHasher->verifyDummy($password);

            return null;
        }

        [$administrator, $passwordHash] = $found;

        return $this->passwordHasher->verify($password, $passwordHash) ? $administrator : null;
    }

    public function administrator(int $id): ?Administrator
    {
        return $this->administrators->findById($id);
    }

    public function isThrottled(string $ip): bool
    {
        return $this->loginThrottle->isLimited($ip);
    }

    /**
     * Records a failed login or setup submission from $ip.
     */
    public function recordFailedAttempt(string $ip): void
    {
        $this->loginThrottle->recordFailure($ip);
    }

    /**
     * @return array<string, string> messages keyed by field name
     */
    private function validateSetupInput(string $name, string $email, string $password, string $passwordConfirmation): array
    {
        $errors = [];
        $nameLength = self::characterCount($name);

        if ($nameLength === null || $nameLength === 0 || $nameLength > self::NAME_MAX_LENGTH) {
            $errors['name'] = sprintf('Enter a name of up to %d characters.', self::NAME_MAX_LENGTH);
        }

        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $passwordLength = self::characterCount($password);

        if ($passwordLength === null || $passwordLength < self::PASSWORD_MIN_LENGTH || $passwordLength > self::PASSWORD_MAX_LENGTH) {
            $errors['password'] = sprintf(
                'Use a password of %d to %d characters.',
                self::PASSWORD_MIN_LENGTH,
                self::PASSWORD_MAX_LENGTH,
            );
        } elseif (!hash_equals($password, $passwordConfirmation)) {
            $errors['password_confirmation'] = 'The passwords do not match.';
        }

        return $errors;
    }

    /**
     * Number of UTF-8 characters, or null for invalid UTF-8. Uses PCRE because
     * mbstring is not a server dependency.
     */
    private static function characterCount(string $value): ?int
    {
        $count = preg_match_all('/./su', $value);

        return $count === false ? null : $count;
    }
}
