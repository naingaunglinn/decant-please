<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Media Disk (user-uploaded images)
    |--------------------------------------------------------------------------
    |
    | The disk fragrance/brand images are written to and served from. Kept
    | separate from the default disk above: locally the default is the private
    | "local" disk, but uploaded images must live on a publicly-served disk.
    | Defaults to "public" (local dev, via storage:link); production sets
    | MEDIA_DISK=s3 to store and serve them from Cloudflare R2. Every image code
    | path reads this one value — the API resources, the Filament FileUpload /
    | ImageColumn components, and the decant:fresh-start command.
    |
    */

    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Proofs Disk (customer payment screenshots)
    |--------------------------------------------------------------------------
    |
    | The disk payment-proof screenshots are written to — PRIVATE, the opposite
    | of the media disk above: a transfer screenshot carries names, numbers,
    | and amounts, so it must never sit under a public domain, however
    | unguessable the filename. Defaults to the stock "local" disk
    | (storage/app/private — its serve route demands a signed URL nothing here
    | generates); production sets PROOFS_DISK=s3-proofs, a second R2 bucket
    | with no custom domain and no url. The only way a proof is ever served is
    | the panel's authenticated streaming route (PaymentProofViewController).
    |
    */

    'proofs_disk' => env('PROOFS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Payment proofs in production (PROOFS_DISK=s3-proofs): a separate,
        // fully private R2 bucket. Deliberately no 'url' key — no public
        // domain serves this bucket; Laravel streams objects to the admin
        // itself. Its own key pair (R2 tokens are scoped per bucket); the
        // endpoint and region are account-level on R2, so they fall back to
        // the main AWS_* values.
        's3-proofs' => [
            'driver' => 's3',
            'key' => env('PROOFS_AWS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('PROOFS_AWS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('PROOFS_AWS_BUCKET'),
            'endpoint' => env('PROOFS_AWS_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
