<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class User extends BaseModel
{
    protected $table = 'mg_users';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['extra' => 'array']);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }
}
