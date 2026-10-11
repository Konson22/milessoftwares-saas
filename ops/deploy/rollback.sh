#!/usr/bin/env bash
# Point `current` back at the previous release. Database migrations are not
# reversed: releases are written to run against the newer schema.
set -euo pipefail

BASE="${BASE:-/srv/miles}"
PHP="${PHP:-php}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"

current="$(readlink -f "$BASE/current")"
# The newest release older than the current one. A newer directory can be
# a deploy that failed before switching, so it is never a candidate.
previous="$(ls -1d "$BASE"/releases/* | sort | awk -v cur="$current" '$0 < cur' | tail -n 1)"

if [[ -z "$previous" ]]; then
    echo "no previous release to roll back to" >&2
    exit 1
fi

ln -sfn "$previous" "$BASE/current.tmp"
mv -T "$BASE/current.tmp" "$BASE/current"
sudo systemctl reload "$PHP_FPM_SERVICE"
"$PHP" "$BASE/current/artisan" queue:restart --no-ansi
"$PHP" "$BASE/current/artisan" inertia:stop-ssr --no-ansi || true

echo "rolled back to $(basename "$previous") ($(cat "$previous/REVISION" 2>/dev/null || echo unknown))"
