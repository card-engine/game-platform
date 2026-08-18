<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Settlement extends BaseModel
{
    protected $table = 'mgs_settlements';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['data' => 'array']);
    }
}
