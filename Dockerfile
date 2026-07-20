# Two-stage build, modelled on nextcloud/app-skeleton-python.
# Build for the target host arch with: docker buildx build --platform linux/amd64

FROM python:3.12-slim-bookworm AS builder

ARG TARGETARCH
ARG FRP_VERSION=0.61.1
# Checksums from the release's own frp_sha256_checksums.txt. frpc receives the
# shared key and tunnels traffic between Nextcloud and this app, so a tampered
# or truncated download must fail the build rather than ship.
ARG FRP_SHA256_AMD64=bff260b68ca7b1461182a46c4f34e9709ba32764eed30a15dd94ac97f50a2c40
ARG FRP_SHA256_ARM64=af6366f2b43920ebfe6235dba6060770399ed1fb18601e5818552bd46a7621f8

WORKDIR /build
COPY requirements.txt .
RUN pip install --no-cache-dir --prefix=/install -r requirements.txt

# frpc: the outbound tunnel client HaRP needs inside every ExApp image.
#
# Retried, because a single failed download used to break the whole build — and
# this repository is meant to be built by someone else's CI, where a blip on
# GitHub's side is not something they can debug.
RUN set -eu; \
    apt-get update && apt-get install -y --no-install-recommends curl ca-certificates; \
    case "${TARGETARCH}" in \
      amd64) FRP_SHA256="${FRP_SHA256_AMD64}" ;; \
      arm64) FRP_SHA256="${FRP_SHA256_ARM64}" ;; \
      *) echo "no pinned frp checksum for arch '${TARGETARCH}'" >&2; exit 1 ;; \
    esac; \
    url="https://github.com/fatedier/frp/releases/download/v${FRP_VERSION}/frp_${FRP_VERSION}_linux_${TARGETARCH}.tar.gz"; \
    for attempt in 1 2 3 4 5; do \
      if curl -fsSL --connect-timeout 20 --max-time 300 --retry 3 --retry-delay 5 \
              --retry-connrefused -o /tmp/frp.tar.gz "$url"; then \
        break; \
      fi; \
      echo "frp download failed (attempt $attempt/5), retrying" >&2; \
      sleep $((attempt * 10)); \
    done; \
    test -s /tmp/frp.tar.gz || { echo "frp could not be downloaded" >&2; exit 1; }; \
    echo "${FRP_SHA256}  /tmp/frp.tar.gz" | sha256sum -c -; \
    tar -xzf /tmp/frp.tar.gz -C /tmp; \
    cp "/tmp/frp_${FRP_VERSION}_linux_${TARGETARCH}/frpc" /usr/local/bin/frpc; \
    chmod +x /usr/local/bin/frpc; \
    rm -rf /tmp/frp* /var/lib/apt/lists/*

FROM python:3.12-slim-bookworm

COPY --from=builder /install /usr/local
COPY --from=builder /usr/local/bin/frpc /usr/local/bin/frpc

# curl — AppAPI/health tooling; procps — pgrep in healthcheck.sh
RUN apt-get update && apt-get install -y --no-install-recommends curl procps \
    && rm -rf /var/lib/apt/lists/*

ADD ex_app/ /ex_app/
COPY start.sh healthcheck.sh /
RUN chmod +x /start.sh /healthcheck.sh

WORKDIR /ex_app/lib
ENTRYPOINT ["/start.sh", "python3", "main.py"]
HEALTHCHECK --interval=2s --timeout=2s --retries=300 CMD /healthcheck.sh
