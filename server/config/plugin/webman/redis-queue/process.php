<?php
return [
    'serial' => [
        'handler'     => Webman\RedisQueue\Process\Consumer::class,
        'count'       => 1,
        'constructor' => [
            'consumer_dir' => app_path() . '/queue/redis/serial'
        ],
        'maxRequest' => 1000,
    ],
    'parallel' => [
        'handler'     => Webman\RedisQueue\Process\Consumer::class,
        'count'       => 2,
        'constructor' => [
            'consumer_dir' => app_path() . '/queue/redis/parallel'
        ],
        'maxRequest' => 1000,
    ]
];
