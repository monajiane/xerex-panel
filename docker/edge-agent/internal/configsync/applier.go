// Package configsync is the agent's "brain": it polls the panel for the
// current set of proxy rules, hands them to the nginx writer, and forces
// a reload on diff. A ForcePull() trigger is exposed for the websocket
// push path.
package configsync

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"sync"
	"time"

	"github.com/xerex/edge-agent/internal/api"
	"github.com/xerex/edge-agent/internal/nginx"
	"go.uber.org/zap"
)

// Options configures the applier.
type Options struct {
	PullInterval time.Duration
	OnChange     func(rulesCount int)
}

// Applier pulls config and writes it to disk.
type Applier struct {
	client   *api.Client
	writer   *nginx.Writer
	log      *zap.Logger
	opts     Options
	lastHash string
	mu       sync.Mutex
	trigger  chan struct{}
}

// NewApplier returns a fresh Applier.
func NewApplier(client *api.Client, w *nginx.Writer, log *zap.Logger, opts Options) *Applier {
	if opts.PullInterval == 0 {
		opts.PullInterval = 30 * time.Second
	}
	if log == nil {
		log = zap.NewNop()
	}
	return &Applier{
		client:  client,
		writer:  w,
		log:     log,
		opts:    opts,
		trigger: make(chan struct{}, 1),
	}
}

// Name implements agent.Component.
func (a *Applier) Name() string { return "configsync" }

// ForcePull schedules an immediate config fetch.
func (a *Applier) ForcePull() {
	select {
	case a.trigger <- struct{}{}:
	default:
		// already pending
	}
}

// Start is the long-running loop.
func (a *Applier) Start(ctx context.Context) error {
	// Run an initial pull right away so the agent converges fast.
	if err := a.tick(ctx); err != nil && !isCtx(err) {
		a.log.Warn("initial config pull failed", zap.Error(err))
	}

	t := time.NewTicker(a.opts.PullInterval)
	defer t.Stop()

	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-t.C:
			if err := a.tick(ctx); err != nil && !isCtx(err) {
				a.log.Warn("config pull failed", zap.Error(err))
			}
		case <-a.trigger:
			if err := a.tick(ctx); err != nil && !isCtx(err) {
				a.log.Warn("forced config pull failed", zap.Error(err))
			}
		}
	}
}

func (a *Applier) tick(ctx context.Context) error {
	hello, err := a.client.Hello(ctx)
	if err != nil {
		return err
	}

	hash := hashRules(hello.Rules)
	a.mu.Lock()
	unchanged := hash == a.lastHash
	a.mu.Unlock()

	if unchanged {
		a.log.Debug("config unchanged, skipping write", zap.String("hash", hash))
		return nil
	}

	if err := a.writer.Apply(ctx, hello.Edge, hello.Rules); err != nil {
		return err
	}

	a.mu.Lock()
	a.lastHash = hash
	a.mu.Unlock()

	if a.opts.OnChange != nil {
		a.opts.OnChange(len(hello.Rules))
	}
	return nil
}

func hashRules(rs []api.ProxyRuleConfig) string {
	h := sha256.New()
	// simple, stable serialisation: just concat IDs+updated fields that matter
	for _, r := range rs {
		h.Write([]byte(r.UUID))
		h.Write([]byte{0})
		h.Write([]byte(r.Type))
		h.Write([]byte{0})
		h.Write([]byte(r.Domain))
		h.Write([]byte{0})
		h.Write([]byte(r.Path))
		h.Write([]byte{0})
		h.Write([]byte(r.Origin.URL))
		h.Write([]byte{0})
	}
	return hex.EncodeToString(h.Sum(nil))[:16]
}

func isCtx(err error) bool {
	return err == context.Canceled || err == context.DeadlineExceeded
}
