#!/bin/bash
# Adapted from nextcloud/app-skeleton-python (AGPL-3.0-or-later).
# Under HaRP the tunnel IS the liveness: if frpc died, Nextcloud can't reach us.
if [ -f /frpc.toml ] && [ -n "$HP_SHARED_KEY" ]; then
  if pgrep -x "frpc" > /dev/null; then
      exit 0
  else
      exit 1
  fi
fi
