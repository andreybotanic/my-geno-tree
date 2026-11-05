#!/bin/sh
set -eu

# Keep the shared proxy credential out of the repository and all model messages.
case "${CLI_PROXY_API_KEY:-}" in
  ''|*[!a-zA-Z0-9_-]*) echo 'CLI_PROXY_API_KEY must contain only letters, digits, underscores or hyphens.' >&2; exit 1 ;;
esac
umask 077
cp /CLIProxyAPI/config.template.yaml /run/cli-proxy-api/config.yaml
printf '\naccess:\n  api-keys:\n    - "%s"\n' "$CLI_PROXY_API_KEY" >> /run/cli-proxy-api/config.yaml
exec /CLIProxyAPI/CLIProxyAPI -config /run/cli-proxy-api/config.yaml "$@"
