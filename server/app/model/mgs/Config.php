<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Config extends BaseModel
{
    protected $table = 'mgs_configs';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['value' => 'array']);
    }
}
