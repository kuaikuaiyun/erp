<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AikaipinProduct extends Model
{
    protected $fillable = [
        'product_code',
        'name',
        'source_platform',
        'product_type',
        'landing_page_url',
        'created_by',
        'created_by_name',
        'updated_by',
        'updated_by_name',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function skus(): HasMany
    {
        return $this->hasMany(AikaipinProductSku::class, 'product_id')->orderBy('sort')->orderBy('id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            AikaipinProductCategory::class,
            'aikaipin_product_category_assignments',
            'product_id',
            'category_id',
        )->withTimestamps()->orderBy('sort')->orderBy('name');
    }

    public function files(): HasMany
    {
        return $this->hasMany(AikaipinProductFile::class, 'product_id')->orderBy('kind')->orderBy('sort')->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(AikaipinProductFile::class, 'product_id')
            ->where('kind', AikaipinProductFile::KIND_IMAGE)
            ->orderBy('sort')
            ->orderBy('id');
    }

    public function referenceFile(): HasOne
    {
        return $this->hasOne(AikaipinProductFile::class, 'product_id')
            ->where('kind', AikaipinProductFile::KIND_REFERENCE)
            ->orderByDesc('id');
    }

    public function packageFile(): HasOne
    {
        return $this->hasOne(AikaipinProductFile::class, 'product_id')
            ->where('kind', AikaipinProductFile::KIND_PACKAGE)
            ->orderByDesc('id');
    }

    public function authorizedDepartments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'aikaipin_product_department_visibilities',
            'product_id',
            'department_id',
        )->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }
}
