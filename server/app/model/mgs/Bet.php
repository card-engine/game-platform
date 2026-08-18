<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Bet extends BaseModel
{
    protected $table = 'mgs_bets_template';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['actions' => 'array']);
    }
}
