<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class RechargeOrder extends BaseModel
{
    protected $table = 'mgs_recharge_orders';
    protected $primaryKey = 'id';
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['rate_snapshot' => 'array']);
    }
}
