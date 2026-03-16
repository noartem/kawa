<?php

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'log' => [
            'driver' => 'single-line-log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
