# Two-stage build, modelled on nextcloud/app-skeleton-python.
# Build for the target host arch with: docker buildx build --platform linux/amd64

FROM python:3.12-slim-bookworm AS builder

ARG TARGETARCH
ARG FRP_VERSION=0.61.1

WORKDIR /build
COPY requirements.txt .
RUN pip install --no-cache-dir --prefix=/install -r requirements.txt

# frpc: the outbound tunnel client HaRP needs inside every ExApp image.
# TODO: pin sha256 per arch (upstream skeleton verifies checksums).
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates \
    && curl -fsSL -o /tmp/frp.tar.gz \
       "https://github.com/fatedier/frp/releases/download/v${FRP_VERSION}/frp_${FRP_VERSION}_linux_${TARGETARCH}.tar.gz" \
    && tar -xzf /tmp/frp.tar.gz -C /tmp \
    && cp "/tmp/frp_${FRP_VERSION}_linux_${TARGETARCH}/frpc" /usr/local/bin/frpc \
    && chmod +x /usr/local/bin/frpc \
    && rm -rf /tmp/frp* /var/lib/apt/lists/*

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
