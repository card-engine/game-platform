<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Merchant extends BaseModel
{
    protected $table = 'mg_merchants';
    protected $primaryKey = 'id';
    protected $hidden = ['secret', 'delete_time'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'ip_whitelist' => 'array', 'language_codes' => 'array', 'monthly_tiers' => 'array',
        ]);
    }

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }

    public function credits()
    {
        return $this->hasMany(MerchantCredit::class, 'merchant_id');
    }

    public function monthlyBills()
    {
        return $this->hasMany(MerchantMonthlyBill::class, 'merchant_id');
    }

    public function searchNameAttr($query, $value): void
    {
        $query->whereAny(['name', 'mch_id'], 'like', "%{$value}%");
    }
}
