<?php

declare(strict_types=1);

return [
    'routes' => [

        // ─── Gallery ──────────────────────────────────────────────────────────
        [
            'name' => 'gallery#index',
            'url'  => '/',
            'verb' => 'GET',
        ],
        [
            'name' => 'gallery#folder',
            'url'  => '/folder/{path}',
            'verb' => 'GET',
            'requirements' => ['path' => '.+'],
        ],
        [
            'name' => 'gallery#images',
            'url'  => '/api/images',
            'verb' => 'GET',
        ],
        [
            'name' => 'gallery#thumbnail',
            'url'  => '/api/thumbnail/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'gallery#preview',
            'url'  => '/api/preview/{fileId}',
            'verb' => 'GET',
        ],

        // ─── Rating ───────────────────────────────────────────────────────────
        [
            'name' => 'rating#get',
            'url'  => '/api/rating/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'rating#setBatch',
            'url'  => '/api/rating/batch',
            'verb' => 'POST',
        ],
        [
            'name' => 'rating#set',
            'url'  => '/api/rating/{fileId}',
            'verb' => 'POST',
        ],
        [
            'name' => 'rating#delete',
            'url'  => '/api/rating/{fileId}',
            'verb' => 'DELETE',
        ],

        // ─── Share ────────────────────────────────────────────────────────────
        [
            'name' => 'share#create',
            'url'  => '/api/share',
            'verb' => 'POST',
        ],
        [
            'name' => 'share#list',
            'url'  => '/api/share',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#get',
            'url'  => '/api/share/{token}',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#update',
            'url'  => '/api/share/{token}',
            'verb' => 'PUT',
        ],
        [
            'name' => 'share#delete',
            'url'  => '/api/share/{token}',
            'verb' => 'DELETE',
        ],

        // ─── Guest (öffentlicher Link ohne Login) ─────────────────────────────
        [
            'name' => 'share#guestView',
            'url'  => '/guest/{token}',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#guestImages',
            'url'  => '/api/guest/{token}/images',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#guestThumbnail',
            'url'  => '/api/guest/{token}/thumbnail/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#guestRate',
            'url'  => '/api/guest/{token}/rate',
            'verb' => 'POST',
        ],
        [
            'name' => 'share#guestVerifyPassword',
            'url'  => '/api/guest/{token}/verify',
            'verb' => 'POST',
        ],

        // ─── Settings ─────────────────────────────────────────────────────────
        [
            'name' => 'settings#getSettings',
            'url'  => '/api/settings',
            'verb' => 'GET',
        ],
        [
            'name' => 'settings#saveSettings',
            'url'  => '/api/settings',
            'verb' => 'POST',
        ],

        // ─── Sync ─────────────────────────────────────────────────────────────
        [
            'name' => 'sync#index',
            'url'  => '/sync',
            'verb' => 'GET',
        ],
        [
            'name' => 'sync#getMappings',
            'url'  => '/api/sync/mappings',
            'verb' => 'GET',
        ],
        [
            'name' => 'sync#addMapping',
            'url'  => '/api/sync/mappings',
            'verb' => 'POST',
        ],
        [
            'name' => 'sync#updateMapping',
            'url'  => '/api/sync/mappings/{id}',
            'verb' => 'PUT',
        ],
        [
            'name' => 'sync#deleteMapping',
            'url'  => '/api/sync/mappings/{id}',
            'verb' => 'DELETE',
        ],
        [
            'name' => 'sync#run',
            'url'  => '/api/sync/run/{id}',
            'verb' => 'POST',
        ],
        [
            'name' => 'sync#getLog',
            'url'  => '/api/sync/log/{id}',
            'verb' => 'GET',
        ],
        [
            'name' => 'sync#getStatus',
            'url'  => '/api/sync/status/{id}',
            'verb' => 'GET',
        ],
    ],
];
