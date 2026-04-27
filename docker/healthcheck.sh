#!/usr/bin/env bash
# Container healthcheck — verifies Nginx serves /healthz AND PHP-FPM responds.
set -Eeuo pipefail

# 1. Nginx + container network stack
curl --fail --silent --show-error --max-time 3 "http://127.0.0.1:8080/healthz" >/dev/null

# 2. PHP-FPM ping (skipped on non-web containers via SKIP_FPM_PING=true)
if [[ "${SKIP_FPM_PING:-false}" != "true" ]]; then
    SCRIPT_NAME=/fpm-ping \
    SCRIPT_FILENAME=/fpm-ping \
    REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 >/dev/null
fi

exit 0
