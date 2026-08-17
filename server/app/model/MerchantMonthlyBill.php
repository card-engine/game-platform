<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class MerchantMonthlyBill extends BaseModel
{
    protected $table = 'mg_merchant_monthly_bills';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['rules_snapshot' => 'array']);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }
}
