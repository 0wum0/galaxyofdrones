#!/bin/bash
# Galaxy of Drones - Cron Wrapper Script
# Usage: Add to Hostinger cron jobs:
#   * * * * * /path/to/your/project/scripts/cron.sh >> /dev/null 2>&1
#
# This script runs the Laravel scheduler which handles:
# - game:tick (every minute) - processes constructions, upgrades, training, movements
# - expedition:generate (every 6 hours)
# - mission:generate (every 6 hours)
# - rank:update (hourly)

# Determine the project root (one level up from scripts/)
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Use the PHP binary available on the system
PHP_BIN=$(which php)

if [ -z "$PHP_BIN" ]; then
    # Fallback paths common on Hostinger
    for path in /usr/bin/php /usr/local/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php84/usr/bin/php; do
        if [ -x "$path" ]; then
            PHP_BIN="$path"
            break
        fi
    done
fi

if [ -z "$PHP_BIN" ]; then
    echo "ERROR: PHP binary not found"
    exit 1
fi

cd "$PROJECT_ROOT" && $PHP_BIN artisan schedule:run >> /dev/null 2>&1
