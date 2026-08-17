<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class UniqueBrand extends BaseModel
{
    protected $table = 'mg_unique_brands';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['names' => 'array']);
    }

    public function providerBrands()
    {
        return $this->hasMany(GameBrand::class, 'unique_brand_id');
    }
}
