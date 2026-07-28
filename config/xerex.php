<?php

return [

    'master' => [
        'token'        => env('XEREX_MASTER_TOKEN'),
        'rate_limit'   => (int) env('XEREX_API_RATE_LIMIT', 120),
        'rate_decay'   => (int) env('XEREX_API_RATE_DECAY', 60),
    ],

    'edge' => [
        'token_ttl'     => (int) env('XEREX_EDGE_TOKEN_TTL', 86400),
        'hmac_secret'   => env('XEREX_EDGE_HMAC_SECRET'),
        'insecure_tls'  => (bool) env('XEREX_EDGE_INSECURE_TLS', false),
        'agent_port'    => 8443,
    ],

    'powerdns' => [
        'api_url'     => env('XEREX_POWERDNS_API_URL', 'http://powerdns:8081'),
        'api_key'     => env('XEREX_POWERDNS_API_KEY'),
        'default_soa' => env('XEREX_POWERDNS_DEFAULT_SOA'),
    ],

    'certbot' => [
        'email'    => env('XEREX_CERTBOT_EMAIL'),
        'staging'  => (bool) env('XEREX_CERTBOT_STAGING', false),
        'webroot'  => env('XEREX_CERTBOT_WEBROOT', '/var/www/letsencrypt'),
    ],

    'nginx' => [
        'reload_cmd'   => env('XEREX_NGINX_RELOAD_CMD', 'docker exec xerex-nginx nginx -s reload'),
        'config_dir'   => env('XEREX_NGINX_CONFIG_DIR', '/etc/nginx/conf.d'),
        'openresty'    => (bool) env('XEREX_OPENRESTY_ENABLED', false),
    ],

    'health' => [
        'interval'      => (int) env('XEREX_HEALTH_CHECK_INTERVAL', 30),
        'timeout'       => (int) env('XEREX_HEALTH_CHECK_TIMEOUT', 5),
        'fail_threshold'=> (int) env('XEREX_HEALTH_CHECK_FAIL_THRESHOLD', 3),
    ],

    'audit' => [
        'enabled' => (bool) env('XEREX_AUDIT_LOG_ENABLED', true),
    ],

    'traffic' => [
        'retention_days' => (int) env('XEREX_TRAFFIC_LOG_RETENTION_DAYS', 30),
    ],
];
