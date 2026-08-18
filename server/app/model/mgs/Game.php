<?php

namespace app\model\mgs;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Game extends BaseModel
{
    protected $table = 'mgs_games';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'names' => 'array', 'currency_codes' => 'array', 'rtp_options' => 'array', 'extra' => 'array',
        ]);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }
}
