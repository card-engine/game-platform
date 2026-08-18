<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class User extends BaseModel
{
    protected $table = 'mgs_users';
    protected $primaryKey = 'id';

    public function wallets()
    {
        return $this->hasMany(Wallet::class, 'user_id');
    }
}
