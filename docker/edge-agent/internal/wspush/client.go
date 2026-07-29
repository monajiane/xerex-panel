// Package wspush subscribes to the panel's Reverb WebSocket channels and
// reacts to push events (config updates, proxy rule changes, etc.).
//
// Reverb speaks the Pusher protocol (ws/pusher), so the client connects to
// <WSURL>/app/<REVERB_APP_KEY>?protocol=7&client=xerex-agent&version=1.0
// and joins the private `edges.{id}` channel via the broadcasting/auth
// endpoint.
//
// On any disconnect the client backs off and reconnects with jitter, never
// blocking the rest of the agent if the WS is down.
package wspush

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/gorilla/websocket"
	"github.com/xerex/edge-agent/internal/api"
	"go.uber.org/zap"
)

// Options configures the websocket client.
type Options struct {
	WSURL             string
	AppKey            string
	OnConfigUpdate    func()
	OnProxyRuleUpdate func(api.ProxyRulePayload)
	ReconnectBase     time.Duration
	ReconnectMax      time.Duration
}

// Client is the long-lived websocket connection.
type Client struct {
	api   *api.Client
	log   *zap.Logger
	opts  Options
	mu    sync.Mutex
	conn  *websocket.Conn
	stop  atomic.Bool
	hello *api.HelloResponse
}

// NewClient returns a new push client.
func NewClient(c *api.Client, log *zap.Logger, opts Options) *Client {
	if log == nil {
		log = zap.NewNop()
	}
	if opts.ReconnectBase == 0 {
		opts.ReconnectBase = 2 * time.Second
	}
	if opts.ReconnectMax == 0 {
		opts.ReconnectMax = 30 * time.Second
	}
	return &Client{api: c, log: log, opts: opts}
}

// Name implements agent.Component.
func (c *Client) Name() string { return "wspush" }

// SetHello allows main to hand the result of the initial GET /api/agent/config
// so the WS client knows which edge it is.
func (c *Client) SetHello(h *api.HelloResponse) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.hello = h
}

// Start is the connection loop. It blocks until ctx is cancelled.
func (c *Client) Start(ctx context.Context) error {
	backoff := c.opts.ReconnectBase
	for {
		if c.stop.Load() {
			return nil
		}
		err := c.dialAndServe(ctx)
		if err == nil || errors.Is(err, context.Canceled) {
			return err
		}
		c.log.Warn("websocket disconnected, will reconnect", zap.Error(err), zap.Duration("backoff", backoff))

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(backoff):
		}

		// exponential backoff with cap + jitter
		backoff *= 2
		if backoff > c.opts.ReconnectMax {
			backoff = c.opts.ReconnectMax
		}
		// 0–25% jitter
		jitter := time.Duration(backoff) / 4
		backoff += time.Duration(time.Now().UnixNano() % int64(jitter))
	}
}

func (c *Client) dialAndServe(ctx context.Context) error {
	c.mu.Lock()
	hello := c.hello
	c.mu.Unlock()
	if hello == nil || hello.Edge.ID == 0 {
		// Defer until the initial config pull has populated hello.
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(2 * time.Second):
		}
		return errors.New("edge identity unknown; waiting for initial config pull")
	}

	wsURL, err := c.buildWSURL()
	if err != nil {
		return err
	}

	headers := http.Header{}
	headers.Set("Authorization", "Bearer "+c.api.Token())
	headers.Set("User-Agent", c.api.UserAgent())

	dialer := websocket.Dialer{
		HandshakeTimeout: 10 * time.Second,
		Subprotocols:     []string{"pusher"},
	}

	conn, resp, err := dialer.DialContext(ctx, wsURL, headers)
	if err != nil {
		if resp != nil {
			return fmt.Errorf("ws dial failed: %w (status=%d)", err, resp.StatusCode)
		}
		return fmt.Errorf("ws dial failed: %w", err)
	}
	defer conn.Close()
	c.mu.Lock()
	c.conn = conn
	c.mu.Unlock()
	c.log.Info("websocket connected", zap.String("url", wsURL))

	// Subscribe to the edges.{id} private channel
	if err := c.subscribe(conn, hello.Edge.ID); err != nil {
		return err
	}

	// Read loop (writes are very rare – just heartbeats when the server asks)
	return c.readLoop(ctx, conn)
}

func (c *Client) buildWSURL() (string, error) {
	// Reverb's recommended URL is ws(s)://host:port/app/<key>?protocol=7&...
	u, err := url.Parse(c.opts.WSURL)
	if err != nil {
		return "", err
	}
	if c.opts.AppKey == "" {
		// Try to pull the key from the panel's /broadcasting/config endpoint
		// (handled in pusher auth dance below). For now leave empty.
		c.opts.AppKey = "xerex-key"
	}
	u.Path = strings.TrimRight(u.Path, "/") + "/app/" + c.opts.AppKey
	q := u.Query()
	q.Set("protocol", "7")
	q.Set("client", "xerex-agent")
	q.Set("version", "1.0")
	u.RawQuery = q.Encode()
	return u.String(), nil
}

func (c *Client) subscribe(conn *websocket.Conn, edgeID uint) error {
	// Private channel: server responds with an auth string after we send
	// {"event":"pusher:subscribe","data":{"channel":"private-edges.<id>","auth":...}}
	// The token is just the panel bearer for the broadcasting/auth endpoint.
	channel := "private-edges." + strconv.FormatUint(uint64(edgeID), 10)
	auth := c.api.Token()
	msg := map[string]any{
		"event": "pusher:subscribe",
		"data": map[string]string{
			"channel": channel,
			"auth":    auth + ":",
		},
	}
	return conn.WriteJSON(msg)
}

func (c *Client) readLoop(ctx context.Context, conn *websocket.Conn) error {
	conn.SetReadLimit(64 * 1024)
	_ = conn.SetReadDeadline(time.Now().Add(90 * time.Second))
	conn.SetPongHandler(func(string) error {
		return conn.SetReadDeadline(time.Now().Add(90 * time.Second))
	})

	// Background ping
	stopPing := make(chan struct{})
	go func() {
		t := time.NewTicker(25 * time.Second)
		defer t.Stop()
		for {
			select {
			case <-stopPing:
				return
			case <-t.C:
				_ = conn.WriteControl(websocket.PingMessage, nil, time.Now().Add(5*time.Second))
			}
		}
	}()
	defer close(stopPing)

	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		default:
		}
		_, raw, err := conn.ReadMessage()
		if err != nil {
			return fmt.Errorf("read message: %w", err)
		}
		c.handle(raw)
	}
}

func (c *Client) handle(raw []byte) {
	var msg struct {
		Event   string          `json:"event"`
		Channel string          `json:"channel"`
		Data    json.RawMessage `json:"data"`
	}
	if err := json.Unmarshal(raw, &msg); err != nil {
		c.log.Debug("ws: ignoring non-json", zap.Error(err))
		return
	}
	switch msg.Event {
	case "pusher:connection_established":
		c.log.Debug("ws: pusher connection established")
	case "pusher:error":
		c.log.Warn("ws: pusher error", zap.ByteString("data", msg.Data))
	case "proxyrule.updated":
		var p api.ProxyRulePayload
		if err := json.Unmarshal(msg.Data, &p); err != nil {
			c.log.Warn("ws: bad proxyrule.updated payload", zap.Error(err))
			return
		}
		if c.opts.OnProxyRuleUpdate != nil {
			c.opts.OnProxyRuleUpdate(p)
		}
		// Reverb emits proxyrule.updated for everything; treat it as a config
		// push hint and re-pull.
		if c.opts.OnConfigUpdate != nil {
			c.opts.OnConfigUpdate()
		}
	default:
		c.log.Debug("ws: unhandled event", zap.String("event", msg.Event))
	}
}

// ---------------------------------------------------------------------------
// broadcasting/auth helper – Pusher private channel auth expects a sha1 HMAC
// of "socket_id:channel" with the secret. Reverb's default is
// REVERB_APP_SECRET. We don't know that secret on the agent side, so we
// delegate to /broadcasting/auth on the panel which signs for us.
// ---------------------------------------------------------------------------

// authRequest asks the panel to sign a private-channel subscription. We
// never see the Reverb secret – the panel computes the HMAC for us.
func (c *Client) authRequest(ctx context.Context, socketID, channel string) (string, error) {
	body := url.Values{}
	body.Set("socket_id", socketID)
	body.Set("channel_name", channel)
	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		strings.TrimRight(c.api.PanelURL(), "/")+"/broadcasting/auth",
		strings.NewReader(body.Encode()))
	if err != nil {
		return "", err
	}
	req.Header.Set("Authorization", "Bearer "+c.api.Token())
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "application/json")

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return "", fmt.Errorf("auth failed: status=%d", resp.StatusCode)
	}
	var out struct {
		Auth string `json:"auth"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return "", err
	}
	return out.Auth, nil
}
