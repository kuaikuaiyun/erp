<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantEmailPurchaseSync extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'actor_id',
        'actor_name',
        'status',
        'source_count',
        'matched_store_count',
        'unmatched_store_count',
        'error_message',
        'completed_at',
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
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
