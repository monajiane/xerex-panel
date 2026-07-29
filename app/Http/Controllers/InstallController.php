<?php

namespace App\Http\Controllers;

use App\Support\Installer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Web-based installer wizard.
 *
 *   /install          → step 1 (requirements)
 *   /install/database → step 2 (DB)
 *   /install/app      → step 3 (APP_URL + admin)
 *   /install/run      → step 4 (migrate + seed + lock)
 *   /install/done     → final landing page
 *
 * Each step is rendered as a tiny self-contained Blade view that POSTs
 * to the next step. The wizard never trusts JavaScript: every form
 * uses CSRF + a server-side validation step.
 *
 * NOTE: This controller is intentionally NOT inside the Api/ namespace
 * and is NOT covered by Sanctum. The wizard runs unauthenticated
 * because the panel does not have a user yet.
 */
class InstallController extends Controller
{
    public function __construct(private readonly Installer $installer)
    {
    }

    /**
     * Step 1 — requirements + welcome.
     */
    public function welcome(): View|RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect()->route('install.done');
        }
        $checks = $this->installer->checkRequirements();
        return view('install.welcome', [
            'checks'   => $checks,
            'passes'   => $this->installer->requirementsPass($checks),
            'phpMin'   => Installer::PHP_MIN,
            'phpFound' => PHP_VERSION,
        ]);
    }

    /**
     * Step 2 — database connection form.
     */
    public function database(): View|RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect()->route('install.done');
        }
        $checks = $this->installer->checkRequirements();
        abort_unless($this->installer->requirementsPass($checks), 412, 'Server requirements not met. Visit /install first.');

        return view('install.database', [
            'defaults' => [
                'driver' => env('DB_CONNECTION', 'mysql'),
                'host'   => env('DB_HOST', '127.0.0.1'),
                'port'   => (string) (int) env('DB_PORT', 3306),
                'name'   => env('DB_DATABASE', 'xerex_panel'),
                'user'   => env('DB_USERNAME', 'xerex'),
            ],
        ]);
    }

    /**
     * POST step 2 — probe the DB and persist credentials, then continue.
     */
    public function databaseStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'driver'   => 'required|in:mysql,pgsql,sqlite',
            'host'     => 'required_unless:driver,sqlite|nullable|string|max:255',
            'port'     => 'required_unless:driver,sqlite|nullable|integer|min:1|max:65535',
            'name'     => 'required|string|max:255',
            'user'     => 'required_unless:driver,sqlite|nullable|string|max:255',
            'password' => 'nullable|string|max:255',
        ]);

        $port = (int) ($data['port'] ?? ($data['driver'] === 'pgsql' ? 5432 : 3306));
        $probe = $this->installer->testDatabaseConnection(
            $data['driver'],
            (string) ($data['host'] ?? '127.0.0.1'),
            $port,
            (string) $data['name'],
            (string) ($data['user'] ?? ''),
            (string) ($data['password'] ?? ''),
        );

        if (! $probe['ok']) {
            return back()->withInput()->withErrors(['db' => $probe['detail']]);
        }

        $this->installer->writeEnvDatabase(
            $data['driver'],
            (string) ($data['host'] ?? '127.0.0.1'),
            $port,
            (string) $data['name'],
            (string) ($data['user'] ?? ''),
            (string) ($data['password'] ?? ''),
        );

        // The new DB config must be visible to the migration step.
        Artisan::call('config:clear');

        return redirect()->route('install.app')
            ->with('install.driver', $data['driver'])
            ->with('install.host', $data['host'] ?? '127.0.0.1')
            ->with('install.port', $port)
            ->with('install.name', $data['name']);
    }

    /**
     * Step 3 — APP_URL + admin user form.
     */
    public function app(): View|RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect()->route('install.done');
        }
        return view('install.app', [
            'defaults' => [
                'app_url'     => url('/'),
                'app_env'     => 'production',
                'admin_name'  => 'Xerex Admin',
                'admin_email' => 'admin@xerex.local',
            ],
        ]);
    }

    /**
     * POST step 3 — write APP_URL/APP_ENV and stash admin details in session.
     * We don't create the admin yet because the DB schema isn't guaranteed
     * to exist (migrations happen on the next step).
     */
    public function appStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_url'       => 'required|url|max:255',
            'app_env'       => 'required|in:local,staging,production',
            'admin_name'    => 'required|string|max:120',
            'admin_email'   => 'required|email|max:190',
            'admin_password' => 'required|string|min:8|max:255|confirmed',
        ]);

        $this->installer->writeEnvApp(rtrim($data['app_url'], '/'), $data['app_env']);
        $this->installer->applySaneDefaults($data['app_env']);

        $appKey = $this->installer->ensureAppKey();

        return redirect()->route('install.run')
            ->with('install.admin_name', $data['admin_name'])
            ->with('install.admin_email', $data['admin_email'])
            ->with('install.admin_password', $data['admin_password'])
            ->with('install.app_url', $data['app_url'])
            ->with('install.app_env', $data['app_env'])
            ->with('install.app_key_preview', substr($appKey, 0, 16));
    }

    /**
     * Step 4 — confirm + run the heavy work.
     */
    public function run(Request $request): View|RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect()->route('install.done');
        }
        $adminEmail = (string) $request->session()->get('install.admin_email', '');
        abort_if($adminEmail === '', 412, 'Session expired. Please restart the installer.');
        return view('install.run', [
            'admin_email' => $adminEmail,
            'app_url'     => (string) $request->session()->get('install.app_url', url('/')),
        ]);
    }

    /**
     * POST step 4 — actually migrate, seed, create the admin, write the lock.
     */
    public function runStore(Request $request): RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect()->route('install.done');
        }

        $name     = (string) $request->session()->get('install.admin_name', '');
        $email    = (string) $request->session()->get('install.admin_email', '');
        $password = (string) $request->session()->get('install.admin_password', '');
        $appUrl   = (string) $request->session()->get('install.app_url', url('/'));

        abort_if($name === '' || $email === '' || $password === '', 412, 'Session expired. Please restart the installer.');

        $mig = $this->installer->runMigrations();
        if (! $mig['ok']) {
            return back()->withErrors(['migrate' => $mig['detail']]);
        }

        $seed = $this->installer->runSeeders();
        if (! $seed['ok']) {
            return back()->withErrors(['seed' => $seed['detail']]);
        }
        $this->installer->runSecuritySeeders();

        try {
            $this->installer->createAdmin($name, $email, $password);
        } catch (RuntimeException $e) {
            return back()->withErrors(['admin' => $e->getMessage()]);
        }

        $this->installer->writeLock([
            'admin_email' => $email,
            'app_url'     => $appUrl,
            'installer'   => 'web',
        ]);

        // Forget admin password from session — it has served its purpose.
        $request->session()->forget(['install.admin_password']);

        return redirect()->route('install.done');
    }

    /**
     * Final landing page.
     */
    public function done(): View
    {
        abort_unless($this->installer->isInstalled(), 404);
        $lockContents = json_decode((string) @file_get_contents(storage_path(Installer::LOCK_FILE)), true) ?: [];
        return view('install.done', [
            'install' => $lockContents,
            'app_url' => $lockContents['app_url'] ?? url('/'),
        ]);
    }
}
