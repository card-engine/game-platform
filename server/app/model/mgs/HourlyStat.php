<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class HourlyStat extends BaseModel
{
    protected $table = 'mgs_hourly_stats';
    protected $primaryKey = 'id';
}
