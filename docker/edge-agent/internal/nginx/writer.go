// Package nginx renders the proxy rules the agent receives from the panel
// into a single /etc/nginx/conf.d/xerex-<edge>.conf file and reloads nginx.
//
// The generator intentionally mirrors the template that lives in
// app/Services/NginxConfigGenerator.php on the panel side, so an edge
// running standalone produces the exact same configuration it would get
// if the panel rendered the file.
package nginx

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"text/template"

	"github.com/xerex/edge-agent/internal/api"
	"go.uber.org/zap"
)

// WriterOptions configures the writer.
type WriterOptions struct {
	ConfDir          string
	NginxTestBin     string
	NginxReloadBin   string
	MainUser         string
	WorkerProcesses  string
	Logger           *zap.Logger
}

// Writer writes and reloads nginx config.
type Writer struct {
	opts  WriterOptions
	log   *zap.Logger
}

// NewWriter returns a Writer.
func NewWriter(opts WriterOptions) *Writer {
	if opts.ConfDir == "" {
		opts.ConfDir = "/etc/nginx/conf.d"
	}
	if opts.NginxTestBin == "" {
		opts.NginxTestBin = "nginx"
	}
	if opts.NginxReloadBin == "" {
		opts.NginxReloadBin = "nginx"
	}
	if opts.MainUser == "" {
		opts.MainUser = "www-data"
	}
	return &Writer{opts: opts}
}

// Apply writes the new config to disk, runs `nginx -t`, and reloads.
func (w *Writer) Apply(ctx context.Context, edge api.EdgeSummary, rules []api.ProxyRuleConfig) error {
	rendered, err := Render(edge, rules, w.opts)
	if err != nil {
		return fmt.Errorf("render template: %w", err)
	}

	confPath := w.configPath(edge)

	// Atomic write: render to a temp file in the same directory then rename.
	tmp := confPath + ".tmp"
	if err := os.WriteFile(tmp, []byte(rendered), 0o644); err != nil {
		return fmt.Errorf("write tmp: %w", err)
	}
	if err := os.Rename(tmp, confPath); err != nil {
		return fmt.Errorf("rename tmp -> final: %w", err)
	}

	// Validate before reloading.
	if err := w.testConfig(ctx); err != nil {
		return fmt.Errorf("nginx -t failed: %w", err)
	}
	if err := w.reload(ctx); err != nil {
		return fmt.Errorf("nginx reload failed: %w", err)
	}
	return nil
}

func (w *Writer) configPath(edge api.EdgeSummary) string {
	name := strings.ToLower(strings.ReplaceAll(edge.Name, " ", "_"))
	if name == "" {
		name = fmt.Sprintf("edge-%d", edge.ID)
	}
	return filepath.Join(w.opts.ConfDir, "xerex-"+name+".conf")
}

func (w *Writer) testConfig(ctx context.Context) error {
	return runCmd(ctx, w.opts.NginxTestBin, "-t")
}

func (w *Writer) reload(ctx context.Context) error {
	// `nginx -s reload` expects the master pid at the default location.
	return runCmd(ctx, w.opts.NginxReloadBin, "-s", "reload")
}

func runCmd(ctx context.Context, name string, args ...string) error {
	cmd := exec.CommandContext(ctx, name, args...)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	cmd.Stdout = &bytes.Buffer{}
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("%s %s: %w (%s)", name, strings.Join(args, " "), err, strings.TrimSpace(stderr.String()))
	}
	return nil
}

// ----------------------------------------------------------------------------
// Template
// ----------------------------------------------------------------------------

type templateData struct {
	Edge            api.EdgeSummary
	Rules           []api.ProxyRuleConfig
	HasWebSocket    bool
	HasGRPC         bool
	HasRedirect     bool
	HasTCP          bool
	MainUser        string
	WorkerProcesses string
}

const tmpl = `# Managed by Xerex Edge Agent — DO NOT EDIT
# Edge: {{.Edge.Name}} (id={{.Edge.ID}} uuid={{.Edge.UUID}})

{{- if .HasTCP}}

stream {
    log_format xerex_stream '$remote_addr [$time_local] '
                          '$protocol $status $bytes_sent $upstream_addr '
                          '$upstream_bytes_sent $upstream_connect_time';
    access_log /var/log/nginx/xerex-stream-access.log xerex_stream;

    {{- range .Rules}}
    {{- if eq .Type "tcp"}}
    upstream xerex_tcp_{{.ID}} {
        {{- if .Origin.URL}}
        server {{.Origin.Host}}:{{.Origin.Port}} weight={{or .Origin.Weight 1}} max_fails={{or .Origin.MaxFails 3}} fail_timeout={{or .Origin.FailTimeout 10}}s;
        {{- end}}
    }
    server {
        listen {{.ListenPort}};
        proxy_pass xerex_tcp_{{.ID}};
        proxy_connect_timeout {{or .Origin.ConnectTimeout 5}}s;
        proxy_timeout {{or .Origin.ReadTimeout 60}}s;
    }
    {{- end}}
    {{- end}}
}
{{- end}}

http {
    {{- if .WorkerProcesses}}
    worker_processes {{.WorkerProcesses}};
    {{- end}}

    map $http_upgrade $connection_upgrade {
        default upgrade;
        ''      close;
    }

    log_format xerex_main '$remote_addr - $remote_user [$time_local] '
                        '"$request" $status $body_bytes_sent '
                        '"$http_referer" "$http_user_agent" '
                        'rt=$request_time uct=$upstream_connect_time '
                        'uht=$upstream_header_time urt=$upstream_response_time';

    access_log /var/log/nginx/xerex-access.log xerex_main;

    {{range .Rules}}
    {{- if ne .Type "tcp"}}
    # Rule {{.ID}} — {{.Domain}}{{.Path}} → {{.Origin.URL}}
    server {
        listen {{.ListenPort}}{{if .HTTP2Enabled}} http2{{end}}{{if .HTTP3Enabled}} http3{{end}};
        server_name {{.Domain}};

        {{- if .ForceHTTPS}}
        return 301 https://$host$request_uri;
        {{- end}}

        {{- if eq .Type "redirect"}}
        location {{if .PathMatchType "prefix"}}*{{end}} {{.Path}} {
            return 301 {{.Origin.URL}}$request_uri;
        }
        {{- else}}
        location {{if .PathMatchType "prefix"}}*{{end}} {{.Path}} {
            proxy_pass {{.Origin.URL}};

            proxy_http_version 1.1;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $scheme;

            proxy_connect_timeout {{or .Origin.ConnectTimeout 5}}s;
            proxy_send_timeout {{or .Origin.SendTimeout 60}}s;
            proxy_read_timeout {{or .Origin.ReadTimeout 60}}s;

            {{- if eq .Type "websocket"}}
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection $connection_upgrade;
            {{- end}}
        }
        {{- end}}
    }
    {{- end}}
    {{end}}
}
`

var tpl = template.Must(template.New("nginx").Parse(tmpl))

// Render returns the rendered nginx config for the given rules.
func Render(edge api.EdgeSummary, rules []api.ProxyRuleConfig, opts WriterOptions) (string, error) {
	data := templateData{
		Edge:            edge,
		Rules:           rules,
		MainUser:        opts.MainUser,
		WorkerProcesses: opts.WorkerProcesses,
	}
	for _, r := range rules {
		switch r.Type {
		case "websocket":
			data.HasWebSocket = true
		case "grpc":
			data.HasGRPC = true
		case "redirect":
			data.HasRedirect = true
		case "tcp":
			data.HasTCP = true
		}
	}
	// Stable order for reproducibility
	sort.SliceStable(data.Rules, func(i, j int) bool {
		if data.Rules[i].ListenPort != data.Rules[j].ListenPort {
			return data.Rules[i].ListenPort < data.Rules[j].ListenPort
		}
		return data.Rules[i].Domain < data.Rules[j].Domain
	})

	var buf bytes.Buffer
	if err := tpl.Execute(&buf, data); err != nil {
		return "", err
	}
	return buf.String(), nil
}
