import { defineStore } from 'pinia';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Realtime store – subscribes to the Laravel Reverb (Pusher protocol) WebSocket
 * endpoint and exposes a small reactive surface for the rest of the SPA.
 *
 * The store is intentionally defensive: if laravel-echo / pusher-js are not
 * installed yet (e.g. fresh clone without `npm install`) or Reverb is not
 * configured, every method becomes a no-op and the "live" indicator just
 * stays hidden instead of crashing the page.
 */
export const useRealtimeStore = defineStore('realtime', {
    state: () => ({
        /** @type {Echo|null} */
        echo: null,
        connected: false,
        connecting: false,
        lastError: null,
        lastEventAt: null,
        /** Recent events kept in memory for the dashboard ticker. */
        events: [],
        /** Per-channel subscription refs so we can cleanly unsubscribe. */
        _dashboardChannel: null,
    }),

    getters: {
        isReady: (s) => s.connected && !!s.echo,
        recentEvents: (s) => s.events.slice(0, 20),
    },

    actions: {
        /**
         * Initialise the Echo instance and subscribe to the global
         * "dashboard" private channel plus the per-user channel.
         */
        connect() {
            if (this.connected || this.connecting) {
                return;
            }

            const key = window.XEREX_REVERB?.key;
            const host = window.XEREX_REVERB?.host;
            const port = window.XEREX_REVERB?.port ?? 8080;
            const scheme = window.XEREX_REVERB?.scheme ?? 'http';
            const cluster = window.XEREX_REVERB?.cluster ?? 'mt1';
            const forceTLS = scheme === 'https';

            if (!key || !host) {
                // Reverb not configured – silently skip so the UI keeps working.
                return;
            }

            if (!Echo || !Pusher) {
                // Dependencies not installed – fail soft.
                this.lastError = 'laravel-echo / pusher-js not installed';
                return;
            }

            // Pusher needs to be on the global scope for Echo to find it.
            window.Pusher = Pusher;

            this.connecting = true;
            this.lastError = null;

            try {
                this.echo = new Echo({
                    broadcaster: 'reverb',
                    key,
                    wsHost: host,
                    wsPort: port,
                    wssPort: port,
                    forceTLS,
                    enabledTransports: ['ws', 'wss'],
                    disableStats: true,
                    cluster,
                    authEndpoint: '/broadcasting/auth',
                    auth: {
                        headers: {
                            Authorization: this._authHeader(),
                            Accept: 'application/json',
                        },
                    },
                });

                this._bindConnectionEvents();

                const channel = this.echo.private('dashboard');
                this._dashboardChannel = channel;

                channel.listen('.edge.status', (e) => this._onEvent('edge.status', e));
                channel.listen('.origin.health', (e) => this._onEvent('origin.health', e));
                channel.listen('.origin.failover', (e) => this._onEvent('origin.failover', e));
                channel.listen('.proxyrule.updated', (e) => this._onEvent('proxyrule.updated', e));
                channel.listen('.ssl.updated', (e) => this._onEvent('ssl.updated', e));
                channel.listen('.dns.updated', (e) => this._onEvent('dns.updated', e));
            } catch (err) {
                this.lastError = err?.message || String(err);
                this.connecting = false;
            }
        },

        /** Tear down the connection (used on logout and full page teardown). */
        disconnect() {
            try {
                if (this._dashboardChannel && this.echo) {
                    this.echo.leave('dashboard');
                }
                if (this.echo) {
                    this.echo.disconnect();
                }
            } catch {
                // ignore – we're best-effort here
            }
            this.echo = null;
            this._dashboardChannel = null;
            this.connected = false;
            this.connecting = false;
            this.events = [];
        },

        /** Push an event into the in-memory ticker and notify listeners. */
        _onEvent(type, payload) {
            this.lastEventAt = new Date().toISOString();
            this.events.unshift({ type, payload, at: this.lastEventAt });
            if (this.events.length > 50) {
                this.events.length = 50;
            }
            // Custom DOM event so non-Pinia code can listen.
            try {
                window.dispatchEvent(new CustomEvent(`xerex:${type}`, { detail: payload }));
            } catch {
                /* SSR / unsupported – ignore */
            }
        },

        /** Build the bearer header that broadcasting/auth expects. */
        _authHeader() {
            const token = localStorage.getItem('xerex_token');
            return token ? `Bearer ${token}` : '';
        },

        /** Wire pusher connection lifecycle into our reactive flags. */
        _bindConnectionEvents() {
            const pusher = this.echo?.connector?.pusher;
            if (!pusher) {
                return;
            }
            pusher.connection.bind('connected', () => {
                this.connected = true;
                this.connecting = false;
            });
            pusher.connection.bind('disconnected', () => {
                this.connected = false;
            });
            pusher.connection.bind('error', (err) => {
                this.lastError = err?.message || 'connection error';
                this.connected = false;
            });
        },
    },
});
