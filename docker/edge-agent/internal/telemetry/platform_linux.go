//go:build linux

package telemetry

import (
	"bufio"
	"os"
	"strconv"
	"strings"
)

// countOpenTCPSockets parses /proc/net/tcp to count ESTABLISHED + LISTEN
// sockets. Returns 0 if the file isn't readable.
func countOpenTCPSockets() int {
	f, err := os.Open("/proc/net/tcp")
	if err != nil {
		return 0
	}
	defer f.Close()

	count := 0
	scanner := bufio.NewScanner(f)
	// first line is a header
	if !scanner.Scan() {
		return 0
	}
	for scanner.Scan() {
		fields := strings.Fields(scanner.Text())
		if len(fields) < 4 {
			continue
		}
		// fields[3] is the state: 01=ESTABLISHED, 0A=LISTEN
		if fields[3] == "01" || fields[3] == "0A" {
			count++
		}
	}
	return count
}

func hostname() (string, error) {
	return os.Hostname()
}
