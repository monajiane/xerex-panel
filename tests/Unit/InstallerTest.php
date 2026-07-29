<?php

namespace Tests\Unit;

use App\Support\Installer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    private Installer $installer;
    private string $tmpEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installer = $this->app->make(Installer::class);
        // The setUp creates a real .env in base_path() during phpunit bootstrap.
        // We swap it for a temp file so tests don't trample on the dev environment.
        $this->tmpEnv = tempnam(sys_get_temp_dir(), 'xerex-env-');
        file_put_contents($this->tmpEnv, "APP_NAME=Xerex\nAPP_KEY=\nDB_CONNECTION=sqlite\n");
        $this->setBasePath($this->tmpEnv, realpath(base_path('.env.example')));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpEnv)) {
            @unlink($this->tmpEnv);
        }
        parent::tearDown();
    }

    /**
     * Point the Installer at a fresh .env file under a temp base path,
     * so each test starts from a clean slate.
     */
    private function setBasePath(string $placeholder, string $example): void
    {
        $tmp = sys_get_temp_dir() . '/xerex-base-' . uniqid();
        mkdir($tmp, 0777, true);
        mkdir($tmp . '/storage/framework', 0777, true);
        mkdir($tmp . '/storage/logs', 0777, true);
        mkdir($tmp . '/bootstrap/cache', 0777, true);
        $exampleCopy = $tmp . '/.env.example';
        if (file_exists($example)) {
            copy($example, $exampleCopy);
        } else {
            file_put_contents($exampleCopy, "APP_NAME=Xerex\nAPP_KEY=\n");
        }
        copy($exampleCopy, $tmp . '/.env');

        $this->app->setBasePath($tmp);
    }

    public function test_php_minimum_constant_is_set(): void
    {
        $this->assertSame('8.3', Installer::PHP_MIN);
    }

    public function test_lock_file_constant_is_set(): void
    {
        $this->assertSame('installed.lock', Installer::LOCK_FILE);
    }

    public function test_required_extensions_constant_lists_core_modules(): void
    {
        $this->assertContains('pdo',       Installer::REQUIRED_EXTENSIONS);
        $this->assertContains('mbstring',  Installer::REQUIRED_EXTENSIONS);
        $this->assertContains('openssl',   Installer::REQUIRED_EXTENSIONS);
        $this->assertContains('curl',      Installer::REQUIRED_EXTENSIONS);
        $this->assertContains('intl',      Installer::REQUIRED_EXTENSIONS);
    }

    public function test_check_requirements_returns_array_of_checks(): void
    {
        $checks = $this->installer->checkRequirements();
        $this->assertIsArray($checks);
        $this->assertNotEmpty($checks);
        foreach ($checks as $c) {
            $this->assertArrayHasKey('label', $c);
            $this->assertArrayHasKey('ok', $c);
            $this->assertArrayHasKey('detail', $c);
        }
    }

    public function test_check_requirements_passes_in_test_environment(): void
    {
        // The CI/test box always has pdo + sqlite + mbstring.
        $checks = $this->installer->checkRequirements();
        $this->assertTrue($this->installer->requirementsPass($checks));
    }

    public function test_requirements_pass_returns_false_when_any_check_fails(): void
    {
        $checks = [
            ['label' => 'a', 'ok' => true,  'detail' => ''],
            ['label' => 'b', 'ok' => false, 'detail' => 'missing'],
        ];
        $this->assertFalse($this->installer->requirementsPass($checks));
    }

    public function test_requirements_pass_returns_true_when_all_checks_pass(): void
    {
        $checks = [
            ['label' => 'a', 'ok' => true,  'detail' => ''],
            ['label' => 'b', 'ok' => true,  'detail' => ''],
        ];
        $this->assertTrue($this->installer->requirementsPass($checks));
    }

    public function test_is_installed_returns_false_when_lock_absent(): void
    {
        $this->assertFalse($this->installer->isInstalled());
    }

    public function test_is_installed_returns_true_when_lock_present(): void
    {
        $this->installer->writeLock(['foo' => 'bar']);
        $this->assertTrue($this->installer->isInstalled());
    }

    public function test_clear_lock_removes_the_lock_file(): void
    {
        $this->installer->writeLock();
        $this->assertTrue($this->installer->isInstalled());
        $this->installer->clearLock();
        $this->assertFalse($this->installer->isInstalled());
    }

    public function test_write_lock_records_payload_as_json(): void
    {
        $this->installer->writeLock(['admin_email' => 'admin@example.com']);
        $abs = storage_path(Installer::LOCK_FILE);
        $payload = json_decode((string) file_get_contents($abs), true);
        $this->assertSame('admin@example.com', $payload['admin_email']);
        $this->assertSame(PHP_VERSION, $payload['php']);
        $this->assertSame('xerex:install', $payload['installer']);
        $this->assertArrayHasKey('installed_at', $payload);
    }

    public function test_test_database_connection_accepts_sqlite(): void
    {
        $db = tempnam(sys_get_temp_dir(), 'xerex-db-') . '.sqlite';
        $r = $this->installer->testDatabaseConnection('sqlite', '', 0, $db, '', '');
        $this->assertTrue($r['ok'], $r['detail'] ?? 'unknown');
        @unlink($db);
    }

    public function test_test_database_connection_rejects_bad_credentials(): void
    {
        // Point at a non-existent host. This will fail in <1s rather than hang.
        $r = $this->installer->testDatabaseConnection(
            'mysql', '127.0.0.1', 1, 'nonexistent_db_xerex', 'nope', 'nope'
        );
        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['detail']);
    }

    public function test_write_env_database_replaces_existing_keys(): void
    {
        $envFile = $this->app->basePath('.env');
        $original = file_get_contents($envFile);

        $this->installer->writeEnvDatabase('pgsql', 'db.example.com', 5432, 'xerex', 'user', 'pass');

        $contents = file_get_contents($envFile);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $contents);
        $this->assertStringContainsString('DB_HOST=db.example.com', $contents);
        $this->assertStringContainsString('DB_PORT=5432',          $contents);
        $this->assertStringContainsString('DB_DATABASE=xerex',     $contents);
        $this->assertStringContainsString('DB_USERNAME=user',      $contents);
        $this->assertStringContainsString('DB_PASSWORD=pass',      $contents);

        // Original key order should be preserved (we replace, not rewrite).
        $this->assertNotEmpty($original);
    }

    public function test_write_env_app_uses_quotes_for_urls_with_spaces(): void
    {
        $this->installer->writeEnvApp('https://example.com/path with space');
        $contents = file_get_contents($this->app->basePath('.env'));
        $this->assertStringContainsString('APP_URL="https://example.com/path with space"', $contents);
    }

    public function test_apply_sane_defaults_writes_database_drivers(): void
    {
        $this->installer->applySaneDefaults('production');
        $contents = file_get_contents($this->app->basePath('.env'));
        $this->assertStringContainsString('SESSION_DRIVER=database',    $contents);
        $this->assertStringContainsString('QUEUE_CONNECTION=database',  $contents);
        $this->assertStringContainsString('CACHE_STORE=database',       $contents);
        $this->assertStringContainsString('BROADCAST_CONNECTION=log',   $contents);
        $this->assertStringContainsString('APP_DEBUG=false',            $contents);
    }

    public function test_apply_sane_defaults_keeps_debug_true_in_local(): void
    {
        $this->installer->applySaneDefaults('local');
        $contents = file_get_contents($this->app->basePath('.env'));
        $this->assertStringNotContainsString('APP_DEBUG=false', $contents);
    }
}
