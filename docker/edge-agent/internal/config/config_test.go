package config

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestLoad_Defaults(t *testing.T) {
	// make sure we don't pick up an existing /etc/xerex/agent.yaml on the
	// test host by pointing --config at a non-existent path explicitly.
	tmp := filepath.Join(t.TempDir(), "missing.yaml")
	cfg, err := Load([]string{"--config", tmp})
	if err != nil {
		t.Fatalf("load: %v", err)
	}
	if cfg.PanelURL == "" {
		t.Errorf("expected default panel URL")
	}
	if cfg.PullIntervalSeconds() <= 0 {
		t.Errorf("expected positive pull interval, got %s", cfg.ConfigPullInterval)
	}
}

func TestLoad_FromYAML(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "agent.yaml")
	contents := `agent_token: "xerx_test"
panel_url: "https://panel.example.com"
edge_name: "edge-amst-01"
config_pull_interval: "5s"
log_level: "debug"
`
	if err := os.WriteFile(path, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}
	cfg, err := Load([]string{"--config", path})
	if err != nil {
		t.Fatalf("load: %v", err)
	}
	if cfg.AgentToken != "xerx_test" {
		t.Errorf("token = %q", cfg.AgentToken)
	}
	if cfg.PanelURL != "https://panel.example.com" {
		t.Errorf("panel url = %q", cfg.PanelURL)
	}
	if cfg.EdgeName != "edge-amst-01" {
		t.Errorf("edge name = %q", cfg.EdgeName)
	}
	if cfg.ConfigPullInterval != 5*time.Second {
		t.Errorf("pull interval = %s, want 5s", cfg.ConfigPullInterval)
	}
	if cfg.LogLevel != "debug" {
		t.Errorf("log level = %q", cfg.LogLevel)
	}
}

func TestLoad_RejectsBadPanelURL(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "agent.yaml")
	contents := `agent_token: "xerx"
panel_url: "ftp://nope"
`
	if err := os.WriteFile(path, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}
	_, err := Load([]string{"--config", path})
	if err == nil {
		t.Fatalf("expected validation error")
	}
}

func TestLoad_RequiresToken(t *testing.T) {
	_, err := Load([]string{"--config", "/nonexistent.yaml"})
	if err == nil {
		t.Fatalf("expected token-missing error from main, not from Load")
	}
}

// helper used by the tests above (PullIntervalSeconds is a tiny helper
// to verify the value is in a positive range).
func (c *Config) PullIntervalSeconds() int { return int(c.ConfigPullInterval / time.Second) }
