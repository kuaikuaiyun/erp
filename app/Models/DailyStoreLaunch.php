<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyStoreLaunch extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    protected $fillable = ['operator_user_id','operator_name','operation_department_id','operation_department_name','assistant_department_id','project_id','project_name','website_system_id','website_system_name','email','remark','status','response_text','processed_by','processed_by_name','processed_at'];
    public function __construct(array $attributes = []) { $this->setConnection(config('admin.database.connection') ?: config('database.default')); parent::__construct($attributes); }
    protected function casts(): array { return ['operator_user_id'=>'integer','operation_department_id'=>'integer','assistant_department_id'=>'integer','project_id'=>'integer','website_system_id'=>'integer','processed_by'=>'integer','processed_at'=>'datetime']; }
    public function statusLabel(): string { return $this->status === self::STATUS_PROCESSED ? '已处理' : '未处理'; }
}
