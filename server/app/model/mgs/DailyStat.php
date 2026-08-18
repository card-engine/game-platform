<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class DailyStat extends BaseModel
{
    protected $table = 'mgs_daily_stats';
    protected $primaryKey = 'id';
}
