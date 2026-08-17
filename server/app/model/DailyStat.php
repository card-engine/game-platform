<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class DailyStat extends BaseModel
{
    protected $table = 'mg_daily_stats';
    protected $primaryKey = 'id';
}
