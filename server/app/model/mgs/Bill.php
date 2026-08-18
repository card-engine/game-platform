<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Bill extends BaseModel
{
    protected $table = 'mgs_bills_template';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['data' => 'array']);
    }
}
