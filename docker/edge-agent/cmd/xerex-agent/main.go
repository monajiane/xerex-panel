// Package main is the entrypoint for the Xerex Edge Agent.
//
// The agent is a small daemon that runs on every edge server and:
//   - Periodically pulls its assigned proxy rules from the panel
//     (or receives them via WebSocket push from the panel).
//   - Renders the corresponding nginx server / upstream blocks to
//     /etc/nginx/conf.d/xerex-<edge>.conf and reloads nginx.
//   - Reports heartbeat / system metrics to the panel.
//   - Tails the nginx access log and ships parsed entries to the panel.
//
// All configuration comes from a YAML file (default /etc/xerex/agent.yaml)
// plus environment variables prefixed with XEREX_ and finally CLI flags.
package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"github.com/xerex/edge-agent/internal/agent"
	"github.com/xerex/edge-agent/internal/api"
	"github.com/xerex/edge-agent/internal/config"
	"github.com/xerex/edge-agent/internal/configsync"
	"github.com/xerex/edge-agent/internal/logshipper"
	"github.com/xerex/edge-agent/internal/nginx"
	"github.com/xerex/edge-agent/internal/telemetry"
	"github.com/xerex/edge-agent/internal/wspush"
	"go.uber.org/zap"
)

const version = "1.0.0"

func main() {
	if err := run(); err != nil {
		fmt.Fprintf(os.Stderr, "fatal: %v\n", err)
		os.Exit(1)
	}
}

func run() error {
	fmt.Printf("\n   xerex edge agent v%s\n   Self-hosted CDN edge daemon\n\n", version)

	cfg, err := config.Load(os.Args[1:])
	if err != nil {
		return fmt.Errorf("load config: %w", err)
	}

	logger, err := buildLogger(cfg)
	if err != nil {
		return fmt.Errorf("build logger: %w", err)
	}
	defer func() { _ = logger.Sync() }()

	logger.Info("xerex edge agent starting",
		zap.String("version", version),
		zap.String("panel_url", cfg.PanelURL),
		zap.String("edge_name", cfg.EdgeName),
	)

	if cfg.AgentToken == "" {
		return errors.New("agent_token is required (set XEREX_AGENT_TOKEN or agent_token in YAML)")
	}

	client := api.NewClient(api.ClientOptions{
		PanelURL:       cfg.PanelURL,
		Token:          cfg.AgentToken,
		UserAgent:      fmt.Sprintf("xerex-agent/%s", version),
		Timeout:        15 * time.Second,
		MaxRetries:     3,
		BackoffInitial: 500 * time.Millisecond,
		BackoffMax:     8 * time.Second,
		Logger:         logger.Named("api"),
	})

	hello, err := client.Hello(context.Background())
	if err != nil {
		logger.Warn("initial hello failed; will retry in background", zap.Error(err))
	} else {
		logger.Info("connected to panel",
			zap.Uint("edge_id", hello.Edge.ID),
			zap.String("edge_uuid", hello.Edge.UUID),
		)
	}

	nginxW := nginx.NewWriter(nginx.WriterOptions{
		ConfDir:         cfg.NginxConfDir,
		NginxTestBin:    cfg.NginxTestBin,
		NginxReloadBin:  cfg.NginxReloadBin,
		MainUser:        cfg.NginxUser,
		WorkerProcesses: cfg.NginxWorkerProcesses,
	})

	applier := configsync.NewApplier(client, nginxW, logger, configsync.Options{
		PullInterval: cfg.ConfigPullInterval,
		OnChange: func(rulesCount int) {
			logger.Info("applied new nginx config", zap.Int("rules", rulesCount))
		},
	})

	tel := telemetry.NewCollector(client, logger, telemetry.Options{
		Interval:         cfg.TelemetryInterval,
		Hostname:         cfg.Hostname,
		AgentVersion:     version,
		ReportCapabilites: cfg.Capabilities,
	})

	shipper := logshipper.NewShipper(client, logger, logshipper.Options{
		AccessLogPath:  cfg.AccessLogPath,
		BatchSize:      cfg.LogBatchSize,
		BatchInterval:  cfg.LogBatchInterval,
	})

	pusher := wspush.NewClient(client, logger, wspush.Options{
		WSURL: cfg.WSURL,
		OnConfigUpdate: func() {
			logger.Info("realtime push received – forcing config pull")
			applier.ForcePull()
		},
	})

	orch := agent.New(agent.Options{
		Components:   []agent.Component{applier, tel, shipper, pusher},
		Logger:       logger.Named("orch"),
		GracefulStop: 25 * time.Second,
	})

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)

	var wg sync.WaitGroup
	wg.Add(1)
	go func() {
		defer wg.Done()
		if err := orch.Start(ctx); err != nil {
			logger.Error("orchestrator exited with error", zap.Error(err))
			cancel()
		}
	}()

	select {
	case sig := <-sigCh:
		logger.Info("received signal, shutting down", zap.String("signal", sig.String()))
		cancel()
	case <-ctx.Done():
	}

	done := make(chan struct{})
	go func() {
		wg.Wait()
		close(done)
	}()

	select {
	case <-done:
		logger.Info("clean shutdown complete")
	case <-time.After(30 * time.Second):
		logger.Warn("shutdown timed out – exiting anyway")
	}
	return nil
}

func buildLogger(cfg *config.Config) (*zap.Logger, error) {
	var lvl zap.AtomicLevel
	switch cfg.LogLevel {
	case "debug":
		lvl = zap.NewAtomicLevelAt(zap.DebugLevel)
	case "warn":
		lvl = zap.NewAtomicLevelAt(zap.WarnLevel)
	case "error":
		lvl = zap.NewAtomicLevelAt(zap.ErrorLevel)
	default:
		lvl = zap.NewAtomicLevelAt(zap.InfoLevel)
	}

	encCfg := zap.NewProductionConfig()
	encCfg.Level = lvl
	encCfg.DisableStacktrace = true
	if cfg.LogFormat == "console" {
		encCfg.Encoding = "console"
		encCfg.EncoderConfig.EncodeLevel = zap.CapitalColorLevelEncoder
	}
	return encCfg.Build()
}
