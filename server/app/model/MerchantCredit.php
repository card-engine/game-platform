<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class MerchantCredit extends BaseModel
{
    protected $table = 'mg_merchant_credits';
    protected $primaryKey = 'id';

    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }
}
