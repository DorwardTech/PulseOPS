#!/bin/sh
set -e

# Template the nginx config with environment variables
envsubst '${APP_PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

# Execute the CMD
exec "$@"
