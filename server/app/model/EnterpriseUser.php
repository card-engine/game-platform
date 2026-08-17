<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;
use plugin\saiadmin\app\model\system\SystemUser;

class EnterpriseUser extends BaseModel
{
    protected $table = 'mg_enterprise_users';
    protected $primaryKey = 'id';

    public function user()
    {
        return $this->belongsTo(SystemUser::class, 'system_user_id');
    }

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }

    public function merchants()
    {
        return $this->belongsToMany(Merchant::class, 'mg_enterprise_user_merchants', 'enterprise_user_id', 'merchant_id');
    }
}
