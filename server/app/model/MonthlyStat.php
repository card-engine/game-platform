<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class MonthlyStat extends BaseModel
{
    protected $table = 'mg_monthly_stats';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['bet_amounts' => 'array']);
    }
}
