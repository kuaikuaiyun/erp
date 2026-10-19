<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 运营侧需求提报草稿：仅保存填写内容，不进入流程，可随时回来继续编辑 */
class ProductRequestDraft extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'purchase_images' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
