<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class User extends BaseModel
{
    protected $table = 'mgs_users';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['unique_id' => 'integer']);
    }

    public function wallets()
    {
        return $this->hasMany(Wallet::class, 'user_id');
    }
}
