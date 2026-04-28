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

        'public_uploads' => [
            'driver' => 'local',
            'root' => '/home/intesoga/whatsapp.integracolombia.com',
            'url' => 'https://whatsapp.integracolombia.com',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => 'admin',
            'secret' => 'uI2IU2TS826sQbiQkunDEzddw',
            'region' => 'us-east-1',
            'bucket' => 'public',
            'url' => 'https://s3images.integracolombia.com/public',
            'endpoint' => 'https://s3images.integracolombia.com',
            'use_path_style_endpoint' => true,
            'throw' => false,
            'report' => false,
        ],

        's3_media' => [
            'driver' => 's3',
            'key' => 'admin',
            'secret' => 'uI2IU2TS826sQbiQkunDEzddw',
            'region' => 'us-east-1',
            'bucket' => 'public',
            'url' => 'https://s3images.integracolombia.com/public',
            'endpoint' => 'https://s3images.integracolombia.com',
            'use_path_style_endpoint' => true,
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
