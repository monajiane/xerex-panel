//go:build !linux

package telemetry

// countOpenTCPSockets is a stub on non-Linux platforms. We could parse
// `netstat -an` but it's not worth pulling another exec call into the hot
// telemetry loop.
func countOpenTCPSockets() int { return 0 }

func hostname() (string, error) { return "unknown", nil }
