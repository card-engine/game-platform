<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class ExchangeRate extends BaseModel
{
    protected $table = 'mg_exchange_rates';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['rate_json' => 'array']);
    }
}
