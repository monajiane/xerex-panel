# Xerex Edge Agent — Makefile
#
# Common targets:
#   make build          Build the binary for the current GOOS/GOARCH
#   make build-all      Cross-compile for linux/amd64, linux/arm64, linux/arm
#   make test           Run the unit tests
#   make vet            go vet ./...
#   make tidy           go mod tidy
#   make docker         Build a Docker image (xerex/edge-agent:dev)
#   make clean          Remove build artefacts

BINARY      := xerex-agent
VERSION     ?= 1.0.0
PKG         := ./cmd/xerex-agent
DIST        := dist
LDFLAGS     := -s -w -X main.version=$(VERSION)

GO          ?= go
GOOS        ?= $(shell $(GO) env GOOS)
GOARCH      ?= $(shell $(GO) env GOARCH)

.PHONY: all build build-all test vet tidy docker clean fmt

all: build

build:
	@mkdir -p $(DIST)
	CGO_ENABLED=0 $(GO) build -trimpath -ldflags "$(LDFLAGS)" -o $(DIST)/$(BINARY) $(PKG)
	@echo "Built $(DIST)/$(BINARY)"

build-all:
	@mkdir -p $(DIST)
	@for arch in amd64 arm64 arm; do \
		echo ">> linux/$$arch"; \
		CGO_ENABLED=0 GOOS=linux GOARCH=$$arch \
			$(GO) build -trimpath -ldflags "$(LDFLAGS)" \
			-o $(DIST)/$(BINARY)-linux-$$arch $(PKG) || exit 1; \
	done
	@ls -lh $(DIST)

test:
	$(GO) test -race -count=1 ./...

vet:
	$(GO) vet ./...

tidy:
	$(GO) mod tidy

fmt:
	$(GO) fmt ./...

docker:
	docker build -t xerex/edge-agent:$(VERSION) -t xerex/edge-agent:dev .

clean:
	rm -rf $(DIST)
