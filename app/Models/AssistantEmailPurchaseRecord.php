<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantEmailPurchaseRecord extends Model
{
    protected $fillable = [
        'normalized_email',
        'first_seen_at',
        'last_seen_at',
        'is_current',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
