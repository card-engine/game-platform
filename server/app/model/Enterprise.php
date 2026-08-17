<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Enterprise extends BaseModel
{
    protected $table = 'mg_enterprises';
    protected $primaryKey = 'id';

    public function merchants()
    {
        return $this->hasMany(Merchant::class, 'enterprise_id');
    }

    public function users()
    {
        return $this->hasMany(EnterpriseUser::class, 'enterprise_id');
    }

    public function searchNameAttr($query, $value): void
    {
        $query->where('name', 'like', "%{$value}%");
    }
}
