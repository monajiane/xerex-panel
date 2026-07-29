# syntax=docker/dockerfile:1.6

# ---------- build stage ----------
FROM golang:1.22-alpine AS build
WORKDIR /src

# Cache modules first
COPY go.mod go.sum* ./
RUN go mod download

# Copy the rest of the source
COPY . .

# Build a static binary so it can run on scratch/distroless
ARG VERSION=1.0.0
ARG TARGETOS=linux
ARG TARGETARCH=amd64
ENV CGO_ENABLED=0 \
    GOOS=${TARGETOS} \
    GOARCH=${TARGETARCH} \
    GOFLAGS=-trimpath

RUN go build \
        -ldflags "-s -w -X main.version=${VERSION}" \
        -o /out/xerex-agent \
        ./cmd/xerex-agent

# ---------- final stage ----------
FROM alpine:3.20
RUN apk add --no-cache ca-certificates tzdata nginx bash \
    && addgroup -S xerex 2>/dev/null \
    && adduser -S -G xerex xerex 2>/dev/null

# Layout
RUN mkdir -p /etc/xerex /var/log/xerex /etc/nginx/conf.d /var/lib/xerex
COPY --from=build /out/xerex-agent /usr/local/bin/xerex-agent
COPY config/agent.example.yaml /etc/xerex/agent.example.yaml
COPY scripts/xerex-agent.service /etc/xerex/xerex-agent.service

# A permissive default config that points at the docker-compose service name
# so the agent can boot out-of-the-box during local dev.
RUN printf '%s\n' \
    'panel_url:   "http://app:8000"' \
    'ws_url:      "ws://reverb:8080"' \
    'log_level:   info' \
    'log_format:  console' \
    > /etc/xerex/agent.yaml

EXPOSE 80
USER xerex
WORKDIR /etc/xerex
ENTRYPOINT ["/usr/local/bin/xerex-agent"]
CMD ["--config", "/etc/xerex/agent.yaml"]
