<?php

/*
 * Where template definitions come from (docs/specs/templates.md section 3).
 *
 * Each source: ['kind' => builtin|local|official|community|custom, ...]
 *   builtin    templates/*.yml in the repository
 *   local      the folder in 'path' (CM_TEMPLATES_PATH), read only; custom trust
 *   official   remote index at 'url', signed with official_public_key
 *   community  remote index at 'url', signed with community_public_key
 *   custom     remote index at 'url', unsigned; custom trust
 * A source of kind local or custom is refused at load time when allow_custom is
 * false (the SaaS edition).
 */
$path = env('CM_TEMPLATES_PATH', '');

return [
    'sources' => [
        ['kind' => 'builtin'],
        ...($path !== '' ? [['kind' => 'local']] : []),
    ],

    // The local folder; empty means off.
    'path' => $path,

    // Custom URLs and the local folder. Off unless an operator switches them on (CM_TEMPLATES_ALLOW_CUSTOM=true).
    'allow_custom' => (bool) env('CM_TEMPLATES_ALLOW_CUSTOM', false),

    // Base64 raw ed25519 public key (32 bytes), or several separated by commas during a
    // key rotation (a signature from any is accepted). Empty until the keys exist.
    'official_public_key' => env('CM_TEMPLATES_OFFICIAL_KEY', ''),
    'community_public_key' => env('CM_TEMPLATES_COMMUNITY_KEY', ''),

    // The template ids the console offers. Others in the catalogue show as "not yet available".
    // RustFS (native) and Uptime Kuma (container, needs a server prepared for containers). The runner and Gitea
    // definitions are built but not yet proven on a real server.
    'offered' => array_values(array_filter(array_map('trim', explode(',', (string) env('CM_TEMPLATES_OFFERED', 'rustfs,uptime-kuma'))))),

    'builtin_path' => base_path('templates'),
];
