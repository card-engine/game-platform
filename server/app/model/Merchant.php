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

    public static function system(): self
    {
        $config = config('game_platforms.self_merchant');
        return (new self())->forceFill([
            'id' => 0, 'enterprise_id' => 0, 'mch_id' => '0', 'name' => 'MGames Demo',
            'wallet_mode' => 1, 'billing_mode' => 2, 'callback_url' => $config['callback_url'],
            'language_codes' => [$config['language']], 'default_language' => $config['language'],
            'timezone' => $config['timezone'], 'timeout_ms' => $config['timeout_ms'], 'status' => 1,
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
