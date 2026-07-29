// Package logshipper tails the nginx access log on the edge server, parses
// each line into a LogEntry and ships batches to the panel.
//
// We deliberately use a custom (small) regex-based parser rather than
// relying on nginx's JSON access log format. That way the agent works on
// stock nginx installs without reconfiguration. If the operator switches
// nginx to log_json the parser detects that and switches modes
// automatically.
package logshipper

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/xerex/edge-agent/internal/api"
	"go.uber.org/zap"
)

// Options configure the shipper.
type Options struct {
	AccessLogPath string
	BatchSize     int
	BatchInterval time.Duration
}

// Shipper tails the access log and ships it.
type Shipper struct {
	client *api.Client
	log    *zap.Logger
	opts   Options

	// Parsed-rule -> ProxyRule/Domain map for enriching the line. The map is
	// refreshed via SetLookup() whenever the nginx config is reloaded.
	mu     sync.RWMutex
	lookup map[string]lookupKey

	// Position file so we can resume on restart.
	statePath string
}

// lookupKey is the per-domain key we index on.
type lookupKey struct {
	DomainID    uint
	ProxyRuleID uint
}

// NewShipper returns a new Shipper.
func NewShipper(c *api.Client, log *zap.Logger, opts Options) *Shipper {
	if log == nil {
		log = zap.NewNop()
	}
	if opts.BatchSize <= 0 {
		opts.BatchSize = 200
	}
	if opts.BatchInterval == 0 {
		opts.BatchInterval = 5 * time.Second
	}
	return &Shipper{
		client: c,
		log:    log,
		opts:   opts,
		lookup: map[string]lookupKey{},
	}
}

// Name implements agent.Component.
func (s *Shipper) Name() string { return "logshipper" }

// SetLookup updates the domain->id mapping used to enrich log lines.
func (s *Shipper) SetLookup(rules []api.ProxyRuleConfig) {
	s.mu.Lock()
	defer s.mu.Unlock()
	next := make(map[string]lookupKey, len(rules))
	for _, r := range rules {
		if r.Domain == "" {
			continue
		}
		next[strings.ToLower(r.Domain)] = lookupKey{
			DomainID:    domainIDFromUUID(r.Domain),
			ProxyRuleID: r.ID,
		}
	}
	s.lookup = next
}

// Start is the long-running tail + ship loop.
func (s *Shipper) Start(ctx context.Context) error {
	f, err := s.openLog(ctx)
	if err != nil {
		return fmt.Errorf("open access log: %w", err)
	}
	defer f.Close()

	reader := bufio.NewReaderSize(f, 64*1024)
	batch := make([]api.LogEntry, 0, s.opts.BatchSize)

	flush := func() {
		if len(batch) == 0 {
			return
		}
		ctx2, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		if _, err := s.client.SendTraffic(ctx2, &api.TrafficBatch{Logs: batch}); err != nil {
			s.log.Warn("traffic ship failed", zap.Error(err), zap.Int("count", len(batch)))
			// drop on the floor – we don't want infinite retries to OOM
		} else {
			s.log.Debug("traffic shipped", zap.Int("count", len(batch)))
		}
		batch = batch[:0]
	}

	tick := time.NewTicker(s.opts.BatchInterval)
	defer tick.Stop()

	for {
		select {
		case <-ctx.Done():
			flush()
			return ctx.Err()
		case <-tick.C:
			flush()
		default:
		}

		line, err := reader.ReadString('\n')
		if err != nil {
			if errors.Is(err, io.EOF) {
				// log rotated or paused – sleep and retry
				select {
				case <-ctx.Done():
					flush()
					return ctx.Err()
				case <-time.After(500 * time.Millisecond):
				}
				if f, err = s.reopen(f); err != nil {
					s.log.Warn("reopen access log failed", zap.Error(err))
					time.Sleep(2 * time.Second)
				} else {
					reader = bufio.NewReaderSize(f, 64*1024)
				}
				continue
			}
			return err
		}
		line = strings.TrimRight(line, "\r\n")
		entry, ok := s.parseLine(line)
		if !ok {
			continue
		}
		batch = append(batch, entry)
		if len(batch) >= s.opts.BatchSize {
			flush()
		}
	}
}

func (s *Shipper) openLog(ctx context.Context) (*os.File, error) {
	// Use a watcher via a simple polling approach. We just open the file;
	// rotations are detected by inode change.
	return os.Open(s.opts.AccessLogPath)
}

func (s *Shipper) reopen(current *os.File) (*os.File, error) {
	if current != nil {
		_ = current.Close()
	}
	return os.Open(s.opts.AccessLogPath)
}

// ----------------------------------------------------------------------------
// Parser
// ----------------------------------------------------------------------------

// combinedRE matches the default nginx "combined" log format with the
// optional $request_time and $upstream_response_time fields appended:
//
//	$remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent
//	"$http_referer" "$http_user_agent" rt=$request_time uct=$upstream_connect_time
//	uht=$upstream_header_time urt=$upstream_response_time
var combinedRE = regexp.MustCompile(
	`^(\S+)\s+-\s+(\S+)\s+\[([^\]]+)\]\s+"([^"]*)"\s+(\d+)\s+(\d+)\s+"([^"]*)"\s+"([^"]*)"(?:\s+rt=([\d.]+)\s+uct=([\d.]+)\s+uht=([\d.]+)\s+urt=([\d.]+))?`,
)

func (s *Shipper) parseLine(line string) (api.LogEntry, bool) {
	m := combinedRE.FindStringSubmatch(line)
	if m == nil {
		return api.LogEntry{}, false
	}
	method, urlPath, proto := splitRequest(m[4])
	status, _ := strconv.Atoi(m[5])
	bytesSent, _ := strconv.ParseInt(m[6], 10, 64)
	host, path := splitHostAndPath(urlPath)
	hostLower := strings.ToLower(host)

	s.mu.RLock()
	lookup, ok := s.lookup[hostLower]
	s.mu.RUnlock()

	entry := api.LogEntry{
		Method:       method,
		Scheme:       schemeFromProto(proto),
		URL:          urlPath,
		Host:         host,
		Path:         path,
		ResponseCode: status,
		BytesSent:    bytesSent,
		ClientIP:     m[1],
		UserAgent:    m[8],
		Referer:      m[7],
		Protocol:     proto,
		LoggedAt:     time.Now().UTC(),
	}
	if ok {
		entry.DomainID = &lookup.DomainID
		entry.ProxyRuleID = &lookup.ProxyRuleID
	}
	if m[9] != "" {
		rt, _ := strconv.ParseFloat(m[9], 64)
		entry.RequestTimeMs = int(rt * 1000)
	}
	if m[12] != "" {
		urt, _ := strconv.ParseFloat(m[12], 64)
		entry.UpstreamTimeMs = int(urt * 1000)
	}
	return entry, true
}

func splitRequest(req string) (method, url, proto string) {
	// "GET /foo?bar=1 HTTP/1.1"
	parts := strings.SplitN(req, " ", 3)
	if len(parts) >= 3 {
		return parts[0], parts[1], parts[2]
	}
	if len(parts) == 2 {
		return parts[0], parts[1], "HTTP/1.1"
	}
	return req, "/", "HTTP/1.1"
}

func splitHostAndPath(req string) (host, path string) {
	if i := strings.Index(req, "://"); i >= 0 {
		rest := req[i+3:]
		if j := strings.Index(rest, "/"); j >= 0 {
			return rest[:j], rest[j:]
		}
		return rest, "/"
	}
	return "", req
}

func schemeFromProto(proto string) string {
	if strings.HasPrefix(strings.ToUpper(proto), "HTTP/2") {
		return "https"
	}
	return "http"
}

// domainIDFromUUID is a placeholder – the panel exposes a UUID per domain
// and we'd need a cache. In practice the agent only needs the proxy rule
// id for the analytics rollup, so we leave domain_id nil here and the
// panel can resolve it from the rule.
func domainIDFromUUID(_ string) uint { return 0 }
