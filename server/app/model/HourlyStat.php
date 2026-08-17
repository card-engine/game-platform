<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class HourlyStat extends BaseModel
{
    protected $table = 'mg_hourly_stats';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['bet_amounts' => 'array', 'exchange_rate_values' => 'array']);
    }
}
