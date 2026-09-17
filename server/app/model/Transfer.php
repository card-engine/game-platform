<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Transfer extends BaseModel
{
    protected $table = 'mgs_transfers';
    protected $primaryKey = 'id';
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['id' => 'string', 'recharge_id' => 'string', 'data' => 'array']);
    }

    public function recharge()
    {
        return $this->belongsTo(Recharge::class, 'recharge_id');
    }
}
