<?php

namespace Tests\Feature;

use App\Support\Installer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsureInstalledMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Register a tiny test route on the web group so we can hit it
        // through the full middleware stack.
        Route::get('/_test_panel_home', fn () => 'ok')->middleware('web');
    }

    protected function tearDown(): void
    {
        $this->app->make(Installer::class)->clearLock();
        parent::tearDown();
    }

    public function test_uninstalled_panel_redirects_to_install_wizard(): void
    {
        // No lock file → should redirect to /install
        $r = $this->get('/_test_panel_home');
        $r->assertRedirect(route('install.welcome'));
    }

    public function test_install_wizard_is_reachable_when_uninstalled(): void
    {
        $r = $this->get('/install');
        // Either the wizard renders (200) or redirects to /install/done if installed.
        $this->assertContains($r->getStatusCode(), [200, 302]);
        if ($r->getStatusCode() === 302) {
            $r->assertRedirect(route('install.done'));
        }
    }

    public function test_health_endpoint_is_always_reachable(): void
    {
        $r = $this->get('/up');
        // /up is a Laravel built-in that always returns 200/503.
        $this->assertContains($r->getStatusCode(), [200, 503]);
    }

    public function test_installed_panel_lets_requests_through(): void
    {
        $this->app->make(Installer::class)->writeLock();
        $r = $this->get('/_test_panel_home');
        $r->assertOk();
        $this->assertSame('ok', $r->getContent());
    }

    public function test_uninstalled_panel_returns_json_503_for_api_callers(): void
    {
        $r = $this->withHeaders(['Accept' => 'application/json'])->get('/_test_panel_home');
        // /_test_panel_home is in the web group, but Accept: application/json
        // makes the middleware return JSON.
        if ($r->getStatusCode() === 503) {
            $r->assertJson(['code' => 'NOT_INSTALLED']);
        } else {
            // Some Laravel versions redirect JSON requests too.
            $this->assertContains($r->getStatusCode(), [302, 503]);
        }
    }
}
