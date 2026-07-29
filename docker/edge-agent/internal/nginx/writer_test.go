package nginx

import (
	"strings"
	"testing"

	"github.com/xerex/edge-agent/internal/api"
)

func TestRender_BasicHTTPServer(t *testing.T) {
	edge := api.EdgeSummary{ID: 1, UUID: "ed-uuid", Name: "edge-fra-01"}
	rules := []api.ProxyRuleConfig{
		{
			ID:        42,
			UUID:      "r-uuid",
			Domain:    "example.com",
			Type:      "http",
			Path:      "/",
			ListenPort: 80,
			Origin: api.OriginConfig{
				URL: "http://origin.example.com",
				Host: "origin.example.com",
				Port: 80,
			},
		},
	}

	out, err := Render(edge, rules, WriterOptions{})
	if err != nil {
		t.Fatalf("render failed: %v", err)
	}

	checks := []string{
		"# Managed by Xerex Edge Agent",
		"edge_fra_01", // sanitised name not present
		"server_name example.com",
		"proxy_pass http://origin.example.com",
	}
	// actually the file name is built elsewhere, we just check content
	checks = checks[:0]
	checks = []string{
		"Managed by Xerex Edge Agent",
		"server_name example.com",
		"proxy_pass http://origin.example.com",
		"proxy_http_version 1.1",
	}
	for _, c := range checks {
		if !strings.Contains(out, c) {
			t.Errorf("expected output to contain %q\n--- got ---\n%s", c, out)
		}
	}
}

func TestRender_WebSocketAddsUpgradeHeaders(t *testing.T) {
	edge := api.EdgeSummary{ID: 1, Name: "edge"}
	rules := []api.ProxyRuleConfig{{
		ID: 1, Domain: "ws.example.com", Type: "websocket", Path: "/ws", ListenPort: 80,
		Origin: api.OriginConfig{URL: "http://127.0.0.1:6000", Host: "127.0.0.1", Port: 6000},
	}}
	out, err := Render(edge, rules, WriterOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "Upgrade $http_upgrade") {
		t.Errorf("expected websocket upgrade headers, got:\n%s", out)
	}
	if !strings.Contains(out, "Connection $connection_upgrade") {
		t.Errorf("expected connection upgrade map, got:\n%s", out)
	}
}

func TestRender_TCPRuleUsesStreamBlock(t *testing.T) {
	edge := api.EdgeSummary{ID: 1, Name: "edge"}
	rules := []api.ProxyRuleConfig{{
		ID: 7, Domain: "tcp.example.com", Type: "tcp", ListenPort: 5432,
		Origin: api.OriginConfig{URL: "tcp://db:5432", Host: "db", Port: 5432},
	}}
	out, err := Render(edge, rules, WriterOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "stream {") {
		t.Errorf("expected stream block, got:\n%s", out)
	}
	if !strings.Contains(out, "xerex_tcp_7") {
		t.Errorf("expected per-rule upstream name, got:\n%s", out)
	}
}

func TestRender_RedirectRule(t *testing.T) {
	edge := api.EdgeSummary{ID: 1, Name: "edge"}
	rules := []api.ProxyRuleConfig{{
		ID: 9, Domain: "old.example.com", Type: "redirect", Path: "/",
		Origin: api.OriginConfig{URL: "https://new.example.com"},
	}}
	out, err := Render(edge, rules, WriterOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "return 301 https://new.example.com$request_uri") {
		t.Errorf("expected 301 redirect, got:\n%s", out)
	}
}

func TestRender_Empty(t *testing.T) {
	edge := api.EdgeSummary{ID: 1, Name: "edge"}
	out, err := Render(edge, nil, WriterOptions{})
	if err != nil {
		t.Fatal(err)
	}
	// even with no rules, the config is valid (just logs + map block)
	if !strings.Contains(out, "map $http_upgrade $connection_upgrade") {
		t.Errorf("expected upgrade map even with no rules:\n%s", out)
	}
}
