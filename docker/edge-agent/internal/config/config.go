// Package config is responsible for loading, parsing, validating and exposing
// the agent configuration. Source precedence (later wins):
//
//  1. Built-in defaults
//  2. YAML file (default /etc/xerex/agent.yaml, override with --config)
//  3. Environment variables prefixed with XEREX_
//  4. CLI flags
package config

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/spf13/viper"
)

// Config is the full, validated agent configuration.
type Config struct {
	// Identity
	EdgeName  string
	Hostname  string
	AgentToken string

	// Networking
	PanelURL string
	WSURL    string

	// Filesystem
	ConfigPath    string
	NginxConfDir  string
	AccessLogPath string
	NginxTestBin    string
	NginxReloadBin  string
	NginxUser        string
	NginxWorkerProcesses int

	// Intervals
	ConfigPullInterval time.Duration
	TelemetryInterval  time.Duration
	LogBatchInterval   time.Duration

	// Sizing
	LogBatchSize int

	// Misc
	LogLevel    string
	LogFormat   string
	TimeFormat  string
	Capabilities []string
}

// Defaults are used when no other source overrides a value.
func Defaults() *Config {
	hostname, _ := os.Hostname()
	return &Config{
		EdgeName:             hostname,
		Hostname:             hostname,
		PanelURL:             "http://localhost:8000",
		WSURL:                "ws://localhost:8080",
		NginxConfDir:         "/etc/nginx/conf.d",
		AccessLogPath:        "/var/log/nginx/access.log",
		NginxTestBin:         "nginx",
		NginxReloadBin:       "nginx",
		NginxUser:            "www-data",
		NginxWorkerProcesses: "auto",
		ConfigPullInterval:   30 * time.Second,
		TelemetryInterval:    30 * time.Second,
		LogBatchInterval:     5 * time.Second,
		LogBatchSize:         200,
		LogLevel:             "info",
		LogFormat:            "json",
		TimeFormat:           time.RFC3339Nano,
		Capabilities: []string{
			"http", "https", "websocket", "grpc", "tcp", "http2", "http3",
		},
	}
}

// Load builds a Config from flags, env, file. The args slice is os.Args[1:].
//
//	agent --config /etc/xerex/agent.yaml --panel-url https://panel.example.com
func Load(args []string) (*Config, error) {
	v := viper.NewWithOptions(viper.KeyDelimiter("."))

	// ---- defaults (map into viper) -----------------------------------------
	defs := Defaults()
	v.SetDefault("edge_name", defs.EdgeName)
	v.SetDefault("hostname", defs.Hostname)
	v.SetDefault("agent_token", defs.AgentToken)
	v.SetDefault("panel_url", defs.PanelURL)
	v.SetDefault("ws_url", defs.WSURL)
	v.SetDefault("nginx_conf_dir", defs.NginxConfDir)
	v.SetDefault("access_log_path", defs.AccessLogPath)
	v.SetDefault("nginx_test_bin", defs.NginxTestBin)
	v.SetDefault("nginx_reload_bin", defs.NginxReloadBin)
	v.SetDefault("nginx_user", defs.NginxUser)
	v.SetDefault("nginx_worker_processes", defs.NginxWorkerProcesses)
	v.SetDefault("config_pull_interval", defs.ConfigPullInterval.String())
	v.SetDefault("telemetry_interval", defs.TelemetryInterval.String())
	v.SetDefault("log_batch_interval", defs.LogBatchInterval.String())
	v.SetDefault("log_batch_size", defs.LogBatchSize)
	v.SetDefault("log_level", defs.LogLevel)
	v.SetDefault("log_format", defs.LogFormat)
	v.SetDefault("time_format", defs.TimeFormat)
	v.SetDefault("capabilities", defs.Capabilities)

	// ---- env ----------------------------------------------------------------
	v.SetEnvPrefix("XEREX")
	v.SetEnvKeyReplacer(strings.NewReplacer(".", "_"))
	v.AutomaticEnv()

	// ---- file ---------------------------------------------------------------
	cfgPath, _ := parseConfigFlag(args)
	if cfgPath == "" {
		for _, candidate := range []string{
			"/etc/xerex/agent.yaml",
			"/etc/xerex/agent.yml",
			"./agent.yaml",
		} {
			if _, err := os.Stat(candidate); err == nil {
				cfgPath = candidate
				break
			}
		}
	}
	if cfgPath != "" {
		v.SetConfigFile(cfgPath)
		v.SetConfigType("yaml")
		if err := v.ReadInConfig(); err != nil {
			// Only fail if user explicitly asked for a file that doesn't exist
			if !errors.Is(err, viper.ConfigFileNotFoundError{}) {
				return nil, fmt.Errorf("read config %s: %w", cfgPath, err)
			}
		}
	}

	// ---- flags --------------------------------------------------------------
	if err := parseAndBindFlags(v, args); err != nil {
		return nil, err
	}

	// ---- assemble -----------------------------------------------------------
	cfg := &Config{
		EdgeName:              v.GetString("edge_name"),
		Hostname:              v.GetString("hostname"),
		AgentToken:            v.GetString("agent_token"),
		PanelURL:              strings.TrimRight(v.GetString("panel_url"), "/"),
		WSURL:                 v.GetString("ws_url"),
		ConfigPath:            cfgPath,
		NginxConfDir:          v.GetString("nginx_conf_dir"),
		AccessLogPath:         v.GetString("access_log_path"),
		NginxTestBin:          v.GetString("nginx_test_bin"),
		NginxReloadBin:        v.GetString("nginx_reload_bin"),
		NginxUser:             v.GetString("nginx_user"),
		NginxWorkerProcesses:  v.GetString("nginx_worker_processes"),
		LogLevel:              v.GetString("log_level"),
		LogFormat:             v.GetString("log_format"),
		TimeFormat:            v.GetString("time_format"),
		Capabilities:          v.GetStringSlice("capabilities"),
	}

	var err error
	if cfg.ConfigPullInterval, err = parseDuration(v.GetString("config_pull_interval")); err != nil {
		return nil, fmt.Errorf("config_pull_interval: %w", err)
	}
	if cfg.TelemetryInterval, err = parseDuration(v.GetString("telemetry_interval")); err != nil {
		return nil, fmt.Errorf("telemetry_interval: %w", err)
	}
	if cfg.LogBatchInterval, err = parseDuration(v.GetString("log_batch_interval")); err != nil {
		return nil, fmt.Errorf("log_batch_interval: %w", err)
	}
	cfg.LogBatchSize = v.GetInt("log_batch_size")
	if cfg.LogBatchSize <= 0 {
		cfg.LogBatchSize = 200
	}

	if err := validate(cfg); err != nil {
		return nil, err
	}
	return cfg, nil
}

func parseDuration(s string) (time.Duration, error) {
	if s == "" {
		return 0, errors.New("empty duration")
	}
	d, err := time.ParseDuration(s)
	if err != nil {
		return 0, err
	}
	if d < time.Second {
		return 0, fmt.Errorf("must be >= 1s, got %s", s)
	}
	return d, nil
}

func validate(c *Config) error {
	if c.PanelURL == "" {
		return errors.New("panel_url is required")
	}
	if !strings.HasPrefix(c.PanelURL, "http://") && !strings.HasPrefix(c.PanelURL, "https://") {
		return errors.New("panel_url must start with http:// or https://")
	}
	if c.NginxConfDir == "" {
		return errors.New("nginx_conf_dir is required")
	}
	if c.AccessLogPath == "" {
		return errors.New("access_log_path is required")
	}
	return nil
}

func parseConfigFlag(args []string) (string, error) {
	for i := 0; i < len(args); i++ {
		a := args[i]
		if a == "--config" || a == "-c" {
			ifi+1 >= len(args) {
				return "", errors.New("--config requires a value")
			}
			return args[i+1], nil
		}
		if strings.HasPrefix(a, "--config=") {
			return strings.TrimPrefix(a, "--config="), nil
		}
	}
	return "", nil
}

func parseAndBindFlags(v *viper.Viper, args []string) error {
	for i := 0; i < len(args); i++ {
		a := args[i]
		if !strings.HasPrefix(a, "--") {
			continue
		}
		eq := strings.SplitN(strings.TrimPrefix(a, "--"), "=", 2)
		key := eq[0]
		var val string
		if len(eq) == 2 {
			val = eq[1]
		} else if i+1 < len(args) && !strings.HasPrefix(args[i+1], "--") {
			val = args[i+1]
			i++
		} else {
			val = "true"
		}
		_ = v.BindEnv(key)
		v.Set(key, val)
	}
	return nil
}

func registerFlags(_ *viper.Viper) {
	// Reserved for future cobra/pflag-based flag definitions. We use positional
	// parsing in parseAndBindFlags to keep dependencies minimal.
}

// EnsureConfigDir creates /etc/xerex if it doesn't exist (best-effort).
func EnsureConfigDir() error {
	dir := filepath.Dir("/etc/xerex/agent.yaml")
	if _, err := os.Stat(dir); errors.Is(err, os.ErrNotExist) {
		return os.MkdirAll(dir, 0o755)
	}
	return nil
}
