#!/usr/bin/env bash
set -e
cd /var/www/html
mkdir -p application/config application/runtime tmp upload
chown -R www-data:www-data application upload tmp application/runtime

if [ ! -f application/config/config.php ]; then
cat > application/config/config.php <<PHP
<?php
return [
  'components' => [
    'db' => [
      'connectionString' => 'pgsql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME}',
      'emulatePrepare' => true,
      'username' => '${DB_USER}',
      'password' => '${DB_PASS}',
      'charset' => 'utf8',
      'tablePrefix' => '${TABLE_PREFIX}',
    ],
    'urlManager' => ['urlFormat' => 'path','showScriptName' => true],
  ],
  'config' => ['publicurl' => '${APP_URL}/'],
];
PHP
fi

exec "$@"
