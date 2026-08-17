<?php

namespace app\bootstrap;

use support\Db;
use Webman\Bootstrap;
use Workerman\Worker;

class Database implements Bootstrap
{
    public static function start(?Worker $worker): void
    {
        Db::connection();
    }
}
