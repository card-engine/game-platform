<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Recharge extends BaseModel
{
    protected $table = 'mgs_recharges';
    protected $primaryKey = 'id';
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['id' => 'string', 'user_id' => 'string', 'rate_snapshot' => 'array']);
    }

    public function transfers()
    {
        return $this->hasMany(Transfer::class, 'recharge_id');
    }
}
