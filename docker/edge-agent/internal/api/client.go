// Package api is the HTTP client used by the agent to talk to the panel.
//
// All requests carry the per-edge bearer token. Errors are typed so callers
// can distinguish between transient failures (5xx, network) and permanent
// ones (4xx – the panel rejected the token or the request shape).
package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/rand"
	"net/http"
	"net/url"
	"strings"
	"time"

	"go.uber.org/zap"
)

// ClientOptions configures the HTTP client.
type ClientOptions struct {
	PanelURL       string
	Token          string
	UserAgent      string
	Timeout        time.Duration
	MaxRetries     int
	BackoffInitial time.Duration
	BackoffMax     time.Duration
	Logger         *zap.Logger
}

// Client is the long-lived HTTP client.
type Client struct {
	opts   ClientOptions
	http   *http.Client
}

// NewClient returns a fresh Client.
func NewClient(opts ClientOptions) *Client {
	if opts.Timeout <= 0 {
		opts.Timeout = 15 * time.Second
	}
	if opts.MaxRetries <= 0 {
		opts.MaxRetries = 3
	}
	if opts.BackoffInitial <= 0 {
		opts.BackoffInitial = 500 * time.Millisecond
	}
	if opts.BackoffMax <= 0 {
		opts.BackoffMax = 8 * time.Second
	}
	if opts.Logger == nil {
		opts.Logger = zap.NewNop()
	}
	return &Client{
		opts: opts,
		http: &http.Client{Timeout: opts.Timeout},
	}
}

// ----------------------------------------------------------------------------
// Typed payloads returned by the panel
// ----------------------------------------------------------------------------

// EdgeSummary is the edge identity echoed back by the panel.
type EdgeSummary struct {
	ID          uint     `json:"id"`
	UUID        string   `json:"uuid"`
	Name        string   `json:"name"`
	Hostname    string   `json:"hostname"`
	Capabilities []string `json:"capabilities"`
}

// OriginConfig is the upstream origin a proxy rule points at.
type OriginConfig struct {
	URL              string `json:"url"`
	Host             string `json:"host"`
	Port             int    `json:"port"`
	Protocol         string `json:"protocol"`
	Weight           int    `json:"weight"`
	MaxFails         int    `json:"max_fails"`
	FailTimeout      int    `json:"fail_timeout"`
	HealthCheckPath  string `json:"health_check_path"`
	ConnectTimeout   int    `json:"connect_timeout"`
	ReadTimeout      int    `json:"read_timeout"`
	SendTimeout      int    `json:"send_timeout"`
}

// ProxyRuleConfig is one proxy rule as returned by /api/agent/config.
type ProxyRuleConfig struct {
	ID              uint            `json:"id"`
	UUID            string          `json:"uuid"`
	Domain          string          `json:"domain"`
	Type            string          `json:"type"`
	Path            string          `json:"path"`
	PathMatchType   string          `json:"path_match_type"`
	ListenPort      int             `json:"listen_port"`
	ForceHTTPS      bool            `json:"force_https"`
	HTTP2Enabled    bool            `json:"http2_enabled"`
	HTTP3Enabled    bool            `json:"http3_enabled"`
	IsPrimary       bool            `json:"is_primary"`
	Priority        int             `json:"priority"`
	Weight          int             `json:"weight"`
	HeadersRequest  json.RawMessage `json:"headers_request"`
	HeadersResponse json.RawMessage `json:"headers_response"`
	CacheRules      json.RawMessage `json:"cache_rules"`
	RateLimit       json.RawMessage `json:"rate_limit"`
	AccessRules     json.RawMessage `json:"access_rules"`
	Origin          OriginConfig    `json:"origin"`
}

// HelloResponse is the shape returned by /api/agent/config.
type HelloResponse struct {
	Edge        EdgeSummary       `json:"edge"`
	Rules       []ProxyRuleConfig `json:"rules"`
	GeneratedAt string            `json:"generated_at"`
}

// TelemetryPayload is the body POSTed to /api/agent/telemetry.
type TelemetryPayload struct {
	AgentVersion       string    `json:"agent_version,omitempty"`
	CPUUsage           float64   `json:"cpu_usage"`
	RAMUsage           float64   `json:"ram_usage"`
	DiskUsage          float64   `json:"disk_usage"`
	BandwidthInBytes   uint64    `json:"bandwidth_in_bytes"`
	BandwidthOutBytes  uint64    `json:"bandwidth_out_bytes"`
	ActiveConnections  int       `json:"active_connections"`
	RequestsPerSecond  int       `json:"requests_per_second"`
	Capabilities       []string  `json:"capabilities,omitempty"`
	CollectedAt        time.Time `json:"collected_at"`
}

// LogEntry is one parsed access log line.
type LogEntry struct {
	DomainID       *uint      `json:"domain_id,omitempty"`
	ProxyRuleID    *uint      `json:"proxy_rule_id,omitempty"`
	Method         string     `json:"method"`
	Scheme         string     `json:"scheme"`
	URL            string     `json:"url"`
	Host           string     `json:"host"`
	Path           string     `json:"path"`
	ResponseCode   int        `json:"response_code"`
	BytesSent      int64      `json:"bytes_sent"`
	BytesReceived  int64      `json:"bytes_received"`
	RequestTimeMs  int        `json:"request_time_ms"`
	UpstreamTimeMs int        `json:"upstream_time_ms"`
	ClientIP       string     `json:"client_ip"`
	UserAgent      string     `json:"user_agent"`
	Referer        string     `json:"referer"`
	Protocol       string     `json:"protocol"`
	Cached         bool       `json:"cached"`
	CacheStatus    string     `json:"cache_status"`
	LoggedAt       time.Time  `json:"logged_at"`
}

// TrafficBatch is the envelope POSTed to /api/agent/traffic.
type TrafficBatch struct {
	Logs []LogEntry `json:"logs"`
}

// HealthResult is the body POSTed to /api/agent/health.
type HealthResult struct {
	CheckType      string `json:"check_type"`
	Target         string `json:"target"`
	Status         string `json:"status"`
	ResponseCode   *int   `json:"response_code,omitempty"`
	LatencyMs      *int   `json:"latency_ms,omitempty"`
	Error          string `json:"error,omitempty"`
	OriginServerID *uint  `json:"origin_server_id,omitempty"`
}

// ProxyRulePayload is the WebSocket push notification body.
type ProxyRulePayload struct {
	Action string `json:"action"` // created|updated|deleted|toggled
	ID     uint   `json:"id"`
	UUID   string `json:"uuid"`
}

// ----------------------------------------------------------------------------
// Errors
// ----------------------------------------------------------------------------

// ErrUnauthorized means the panel rejected the token – the agent should stop
// and require a re-registration.
var ErrUnauthorized = errors.New("unauthorized")

// ErrPermanent is returned for 4xx responses that won't succeed on retry.
type ErrPermanent struct{ Status int; Body string }

func (e *ErrPermanent) Error() string {
	return fmt.Sprintf("permanent error: status=%d body=%q", e.Status, e.Body)
}

// ----------------------------------------------------------------------------
// Methods
// ----------------------------------------------------------------------------

// Hello performs the initial handshake / config pull.
func (c *Client) Hello(ctx context.Context) (*HelloResponse, error) {
	var resp HelloResponse
	if err := c.doJSON(ctx, http.MethodGet, "/api/agent/config", nil, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// SendTelemetry uploads a telemetry sample.
func (c *Client) SendTelemetry(ctx context.Context, t *TelemetryPayload) error {
	return c.doJSON(ctx, http.MethodPost, "/api/agent/telemetry", t, nil)
}

// SendTraffic uploads a batch of access log entries.
func (c *Client) SendTraffic(ctx context.Context, b *TrafficBatch) (int, error) {
	var resp struct {
		Inserted int `json:"inserted"`
	}
	if err := c.doJSON(ctx, http.MethodPost, "/api/agent/traffic", b, &resp); err != nil {
		return 0, err
	}
	return resp.Inserted, nil
}

// SendHealth uploads a health check result.
func (c *Client) SendHealth(ctx context.Context, h *HealthResult) error {
	return c.doJSON(ctx, http.MethodPost, "/api/agent/health", h, nil)
}

// PanelURL exposes the configured panel URL (used to derive the WS URL).
func (c *Client) PanelURL() string { return c.opts.PanelURL }

// Token returns the bearer token (used by the WS handshake).
func (c *Client) Token() string { return c.opts.Token }

// UserAgent returns the configured User-Agent header.
func (c *Client) UserAgent() string { return c.opts.UserAgent }

// ----------------------------------------------------------------------------
// Internal HTTP plumbing
// ----------------------------------------------------------------------------

func (c *Client) doJSON(ctx context.Context, method, path string, body, out any) error {
	var reqBody io.Reader
	if body != nil {
		buf, err := json.Marshal(body)
		if err != nil {
			return fmt.Errorf("marshal body: %w", err)
		}
		reqBody = bytes.NewReader(buf)
	}

	endpoint, err := c.buildURL(path)
	if err != nil {
		return err
	}

	attempts := c.opts.MaxRetries + 1
	var lastErr error

	for i := 0; i < attempts; i++ {
		if i > 0 {
			delay := backoff(c.opts.BackoffInitial, c.opts.BackoffMax, i)
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(delay):
			}
		}

		req, err := http.NewRequestWithContext(ctx, method, endpoint, reqBody)
		if err != nil {
			return err
		}
		req.Header.Set("Authorization", "Bearer "+c.opts.Token)
		req.Header.Set("Accept", "application/json")
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("User-Agent", c.opts.UserAgent)

		c.opts.Logger.Debug("http request",
			zap.String("method", method),
			zap.String("url", endpoint),
			zap.Int("attempt", i+1),
		)

		resp, err := c.http.Do(req)
		if err != nil {
			lastErr = err
			c.opts.Logger.Warn("http transport error", zap.Error(err), zap.Int("attempt", i+1))
			continue
		}

		bodyBytes, _ := io.ReadAll(resp.Body)
		_ = resp.Body.Close()

		if resp.StatusCode == http.StatusUnauthorized {
			return ErrUnauthorized
		}
		if resp.StatusCode >= 500 {
			lastErr = fmt.Errorf("server error: status=%d body=%q", resp.StatusCode, truncate(bodyBytes, 256))
			c.opts.Logger.Warn("panel returned 5xx", zap.Int("status", resp.StatusCode), zap.Int("attempt", i+1))
			continue
		}
		if resp.StatusCode >= 400 {
			return &ErrPermanent{Status: resp.StatusCode, Body: string(bodyBytes)}
		}

		if out != nil && len(bodyBytes) > 0 {
			if err := json.Unmarshal(bodyBytes, out); err != nil {
				return fmt.Errorf("decode response: %w (body=%q)", err, truncate(bodyBytes, 256))
			}
		}
		return nil
	}
	return fmt.Errorf("exhausted retries: %w", lastErr)
}

func (c *Client) buildURL(path string) (string, error) {
	base := strings.TrimRight(c.opts.PanelURL, "/")
	full, err := url.JoinPath(base, path)
	if err != nil {
		return "", fmt.Errorf("join url: %w", err)
	}
	return full, nil
}

func backoff(initial, max time.Duration, attempt int) time.Duration {
	d := initial * (1 << (attempt - 1))
	if d > max {
		d = max
	}
	// full jitter
	jitter := time.Duration(rand.Int63n(int64(d)))
	return d/2 + jitter/2
}

func truncate(b []byte, n int) string {
	if len(b) <= n {
		return string(b)
	}
	return string(b[:n]) + "…"
}
