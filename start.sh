#!/bin/bash
service cron start

if [ -S /var/run/docker.sock ]; then
  SOCK_GID=$(stat -c %g /var/run/docker.sock)
  if ! getent group docker >/dev/null; then
    groupadd -g "$SOCK_GID" docker || true
  fi
  usermod -aG docker www-data || true
fi

if [ -n "$HTTP_AUTH_USER" ] && [ -n "$HTTP_AUTH_PASS" ]; then
    htpasswd -cb /etc/apache2/.htpasswd "$HTTP_AUTH_USER" "$HTTP_AUTH_PASS"
    cat > /etc/apache2/app-auth.conf <<'AUTHEOF'
AuthType Basic
AuthName "Restricted Access"
AuthUserFile /etc/apache2/.htpasswd
Require valid-user
AUTHEOF
    echo "HTTP Basic Auth enabled for user: $HTTP_AUTH_USER"
else
    echo "Require all granted" > /etc/apache2/app-auth.conf
    echo "HTTP Basic Auth disabled (no HTTP_AUTH_USER/HTTP_AUTH_PASS set)"
fi

/bin/bash /var/www/html/bin/monitor_metrics.sh
apache2-foreground
