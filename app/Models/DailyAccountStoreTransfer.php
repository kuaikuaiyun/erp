<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyAccountStoreTransfer extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    protected $fillable = ['operator_user_id','operator_name','operation_department_id','operation_department_name','assistant_department_id','target_type','action_type','reason','reason_images','agency_id','agency_name','media_name','account_name','account_number','project_id','project_name','website_system_id','website_system_name','store_domain','status','response_text','processed_by','processed_by_name','processed_at'];
    public function __construct(array $attributes=[]){$this->setConnection(config('admin.database.connection')?:config('database.default'));parent::__construct($attributes);}
    protected function casts():array{return['operator_user_id'=>'integer','operation_department_id'=>'integer','assistant_department_id'=>'integer','agency_id'=>'integer','project_id'=>'integer','website_system_id'=>'integer','processed_by'=>'integer','processed_at'=>'datetime','reason_images'=>'array'];}
    public function statusLabel():string{return $this->status===self::STATUS_PROCESSED?'已处理':'未处理';}
    public function targetLabel():string{return $this->target_type==='store'?'店铺':'账号';}
    public function actionLabel():string{return $this->action_type==='transfer'?'转让':'弃用';}
}
