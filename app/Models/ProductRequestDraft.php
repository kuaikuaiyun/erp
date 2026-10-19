<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 草稿：仅保存填写内容，不进入流程，可随时回来继续编辑（operation 需求提报 / procurement 商品信息检验） */
class ProductRequestDraft extends Model
{
    public const STAGE_OPERATION = 'operation';
    public const STAGE_PROCUREMENT = 'procurement';

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
            'payload' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
