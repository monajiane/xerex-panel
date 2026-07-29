// Package telemetry periodically samples host metrics (CPU, RAM, disk,
// bandwidth, active connections) and POSTs them to /api/agent/telemetry.
//
// All platform-specific calls go through gopsutil which works on Linux,
// macOS and Windows. Sampling is cheap – we use instantaneous values
// rather than building deltas.
package telemetry

import (
	"context"
	"runtime"
	"time"

	"github.com/shirou/gopsutil/v3/cpu"
	"github.com/shirou/gopsutil/v3/disk"
	"github.com/shirou/gopsutil/v3/load"
	"github.com/shirou/gopsutil/v3/mem"
	"github.com/shirou/gopsutil/v3/net"
	"github.com/xerex/edge-agent/internal/api"
	"go.uber.org/zap"
)

// Options configures the collector.
type Options struct {
	Interval          time.Duration
	Hostname          string
	AgentVersion      string
	ReportCapabilites []string
}

// Collector samples host metrics on a fixed interval.
type Collector struct {
	client *api.Client
	log    *zap.Logger
	opts   Options
}

// NewCollector returns a Collector.
func NewCollector(c *api.Client, log *zap.Logger, opts Options) *Collector {
	if log == nil {
		log = zap.NewNop()
	}
	if opts.Interval == 0 {
		opts.Interval = 30 * time.Second
	}
	if opts.Hostname == "" {
		opts.Hostname, _ = hostname()
	}
	return &Collector{client: c, log: log, opts: opts}
}

// Name implements agent.Component.
func (c *Collector) Name() string { return "telemetry" }

// Start runs the periodic sample loop.
func (c *Collector) Start(ctx context.Context) error {
	// Run a sample immediately so the panel gets fresh data on boot.
	if err := c.tick(ctx); err != nil {
		c.log.Debug("initial telemetry sample failed", zap.Error(err))
	}
	t := time.NewTicker(c.opts.Interval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-t.C:
			if err := c.tick(ctx); err != nil {
				c.log.Debug("telemetry sample failed", zap.Error(err))
			}
		}
	}
}

func (c *Collector) tick(ctx context.Context) error {
	sample, err := c.sample()
	if err != nil {
		return err
	}
	if err := c.client.SendTelemetry(ctx, sample); err != nil {
		return err
	}
	c.log.Debug("telemetry sent",
		zap.Float64("cpu", sample.CPUUsage),
		zap.Float64("ram", sample.RAMUsage),
		zap.Float64("disk", sample.DiskUsage),
		zap.Uint64("rx", sample.BandwidthInBytes),
		zap.Uint64("tx", sample.BandwidthOutBytes),
	)
	return nil
}

func (c *Collector) sample() (*api.TelemetryPayload, error) {
	now := time.Now()

	cpuPct, _ := cpuPercent()
	vm, _ := mem.VirtualMemory()
	du, _ := diskUsage("/")
	loadAvg, _ := load.Avg()
	rx, tx, _ := netTotals()

	ramPct := float64(0)
	if vm != nil {
		ramPct = vm.UsedPercent
	}
	diskPct := float64(0)
	if du != nil {
		diskPct = du.UsedPercent
	}
	_ = loadAvg // future use

	return &api.TelemetryPayload{
		AgentVersion:      c.opts.AgentVersion,
		CPUUsage:          cpuPct,
		RAMUsage:          ramPct,
		DiskUsage:         diskPct,
		BandwidthInBytes:  rx,
		BandwidthOutBytes: tx,
		ActiveConnections: activeConnections(),
		Capabilities:      c.opts.ReportCapabilites,
		CollectedAt:       now,
	}, nil
}

func cpuPercent() (float64, error) {
	// Sample twice with a 100ms gap so gopsutil can compute a delta
	pcts, err := cpu.Percent(100*time.Millisecond, false)
	if err != nil {
		return 0, err
	}
	if len(pcts) == 0 {
		return 0, nil
	}
	return pcts[0], nil
}

func diskUsage(path string) (*disk.UsageStat, error) {
	if runtime.GOOS == "windows" {
		path = "C:\\"
	}
	return disk.Usage(path)
}

func netTotals() (rx, tx uint64, err error) {
	io, err := net.IOCounters(false)
	if err != nil || len(io) == 0 {
		return 0, 0, err
	}
	return io[0].BytesRecv, io[0].BytesSent, nil
}

func activeConnections() int {
	// On Linux, /proc/net/tcp gives the count of open TCP sockets across all
	// states. This is a cheap proxy for "active connections" – good enough
	// for the dashboard. On non-Linux platforms we return 0.
	return countOpenTCPSockets()
}
