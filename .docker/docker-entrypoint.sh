#!/bin/sh
set -eu

runtime_ini="/usr/local/etc/php/conf.d/zz-project-opcache.ini"
if [ "${APP_ENV:-development}" = "production" ]; then
    cp /usr/local/etc/php/conf.d/project-production.ini "$runtime_ini"
else
    rm -f "$runtime_ini"
fi

exec "$@"
