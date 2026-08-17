<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class MerchantBill extends BaseModel
{
    public $timestamps = false;
    protected $table = 'mg_merchant_bills';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['data' => 'array']);
    }
}
