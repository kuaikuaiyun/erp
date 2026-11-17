<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetComputerEquipment extends Model
{
    protected $table = 'asset_computer_equipment';

    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['monthly_rent' => 'decimal:2'];
    }
}
