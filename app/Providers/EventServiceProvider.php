<?php

namespace App\Providers;

use App\Events\FailoverTriggered;
use App\Events\OriginHealthChanged;
use App\Listeners\TriggerEdgeSyncOnFailover;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Maps application events to their listeners. Wired up in bootstrap/app.php
 * via the Event facade so it runs even without the framework's auto-discovery.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * Explicit listener bindings - preferred over auto-discovery so the wiring
     * is obvious to readers and to static analysers.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        OriginHealthChanged::class => [
            // Future: trigger alerts (Slack, PagerDuty) etc.
        ],
        FailoverTriggered::class => [
            TriggerEdgeSyncOnFailover::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }

    public function boot(): void
    {
        // Programmatic subscription is also fine in addition to the $listen map.
        Event::listen(FailoverTriggered::class, [TriggerEdgeSyncOnFailover::class, 'handle']);
    }
}
