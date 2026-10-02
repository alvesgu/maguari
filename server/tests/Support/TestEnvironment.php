<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Support;

use Maguari\Server\Access\AccessApi;
use Maguari\Server\Access\Administrator;
use Maguari\Server\Access\SeedConfigReader;
use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Clients\EnrolledClient;
use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Http\App;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Database\Migrator;
use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Server\Kernel\Secrets\SecretKeyFile;
use Maguari\Server\Monitoring\MonitoringApi;
use Slim\App as SlimApp;

/**
 * A migrated database and a secret key file in a temporary directory, with a
 * fixed clock and fake Google Cloud access (no network).
 */
final class TestEnvironment
{
    public const ADMINISTRATOR_EMAIL = 'jane@example.com';
    public const ADMINISTRATOR_PASSWORD = 'correct horse battery';

    public readonly string $directory;
    public readonly Database $database;
    public readonly FixedClock $clock;
    public readonly SecretKeyFile $secretKeyFile;
    public readonly SecretBox $secretBox;
    public readonly AccessApi $access;
    public readonly FakeTokenSource $tokens;
    public readonly FakeHttpClient $http;
    public readonly FleetApi $fleet;
    public readonly MonitoringApi $monitoring;
    public readonly ClientsApi $clients;

    public function __construct(bool $migrate = true)
    {
        $this->directory = sys_get_temp_dir() . '/maguari-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->database = new Database($this->directory . '/maguari.sqlite');
        $this->clock = new FixedClock();
        $this->secretKeyFile = new SecretKeyFile($this->directory . '/secret.key');
        $this->secretKeyFile->create();
        $this->secretBox = new SecretBox($this->secretKeyFile->read());

        if ($migrate) {
            $this->database->create();
            (new Migrator($this->database))->migrate();
        }

        // Never the real /etc/maguari/seed.ini of the machine running the tests.
        $this->access = new AccessApi($this->database, $this->clock, new SeedConfigReader($this->directory . '/seed.ini'));
        $this->tokens = new FakeTokenSource();
        $this->http = new FakeHttpClient();
        $this->fleet = new FleetApi($this->database, $this->clock, $this->tokens, $this->http);
        $this->monitoring = new MonitoringApi($this->database);
        $this->clients = new ClientsApi($this->database, $this->clock, $this->secretBox, $this->monitoring);
    }

    public function writeSeedConfig(string $contents): void
    {
        file_put_contents($this->directory . '/seed.ini', $contents);
    }

    public function app(): SlimApp
    {
        return App::create($this->access, $this->fleet, $this->clients, MAGUARI_TEST_SESSION_PATH, $this->clock, false);
    }

    public function browser(string $ip = '192.0.2.10'): Browser
    {
        return new Browser($this->app(), $ip);
    }

    public function createAdministrator(): Administrator
    {
        $issued = $this->access->issueSetupToken();

        return $this->access->completeSetup(
            $issued->token,
            'Jane Doe',
            self::ADMINISTRATOR_EMAIL,
            self::ADMINISTRATOR_PASSWORD,
            self::ADMINISTRATOR_PASSWORD,
        );
    }

    /**
     * Issues a token for the instance and enrolls a client with it.
     */
    public function enrollClient(int $instanceId): EnrolledClient
    {
        $token = $this->clients->issueEnrollmentToken($instanceId)->token;

        return $this->clients->enroll(json_encode(['protocol_version' => 1, 'client_version' => '0.1.0', 'token' => $token], JSON_THROW_ON_ERROR));
    }

    public function cleanUp(): void
    {
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory);
    }
}
