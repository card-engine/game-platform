<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Wallet extends BaseModel
{
    protected $table = 'mgs_wallets';
    protected $primaryKey = 'id';
}
