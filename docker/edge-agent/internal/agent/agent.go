// Package agent wires the long-running components (config sync, telemetry,
// log shipper, websocket) together and manages their lifecycle.
package agent

import (
	"context"
	"errors"
	"sync"
	"time"

	"go.uber.org/zap"
)

// Component is the contract every long-running part of the agent fulfils.
//
// Start must return once the goroutine has finished (cleanly or not). If
// the component fails with a non-nil error, the orchestrator tears the
// whole agent down (caller surfaces a non-zero exit).
type Component interface {
	Name() string
	Start(ctx context.Context) error
}

// Options configures the orchestrator.
type Options struct {
	Components   []Component
	Logger       *zap.Logger
	GracefulStop time.Duration
}

// Orchestrator is the runtime that supervises every Component.
type Orchestrator struct {
	opts Options
}

// New returns a new orchestrator.
func New(opts Options) *Orchestrator {
	if opts.Logger == nil {
		opts.Logger = zap.NewNop()
	}
	if opts.GracefulStop == 0 {
		opts.GracefulStop = 15 * time.Second
	}
	return &Orchestrator{opts: opts}
}

// Start runs every component in its own goroutine and blocks until:
//   - all components have stopped cleanly, or
//   - one component exits with an error (which cancels the rest), or
//   - the supplied context is cancelled (graceful shutdown).
func (o *Orchestrator) Start(ctx context.Context) error {
	compCtx, cancel := context.WithCancel(ctx)
	defer cancel()

	var wg sync.WaitGroup
	errCh := make(chan error, len(o.opts.Components))

	for _, c := range o.opts.Components {
		c := c
		wg.Add(1)
		go func() {
			defer wg.Done()
			o.opts.Logger.Info("starting component", zap.String("name", c.Name()))
			err := c.Start(compCtx)
			if err != nil && !errors.Is(err, context.Canceled) {
				o.opts.Logger.Error("component failed", zap.String("name", c.Name()), zap.Error(err))
				errCh <- err
				cancel()
				return
			}
			o.opts.Logger.Info("component stopped", zap.String("name", c.Name()))
		}()
	}

	// First error wins; otherwise wait for the ctx to be cancelled.
	var firstErr error
	done := make(chan struct{})
	go func() {
		wg.Wait()
		close(done)
	}()

	select {
	case err := <-errCh:
		firstErr = err
	case <-done:
		firstErr = nil
	case <-ctx.Done():
		// Trigger component cancellation and wait briefly.
		cancel()
		select {
		case <-done:
		case <-time.After(o.opts.GracefulStop):
			o.opts.Logger.Warn("graceful stop window elapsed, returning")
		}
		firstErr = ctx.Err()
	}

	if firstErr != nil && !errors.Is(firstErr, context.Canceled) {
		return firstErr
	}
	return nil
}
