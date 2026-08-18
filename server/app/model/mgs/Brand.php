<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Brand extends BaseModel
{
    protected $table = 'mgs_brands';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['names' => 'array', 'extra' => 'array']);
    }
}
