<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class OperationTaskAsset extends Model { protected $guarded=[]; public function __construct(array $a=[]){$this->setConnection(config('admin.database.connection')?:config('database.default'));parent::__construct($a);} protected function casts():array{return['ai_confirmed'=>'boolean','first_live_at'=>'datetime','retired_at'=>'datetime','version_history'=>'array'];} public function task():BelongsTo{return $this->belongsTo(OperationTestTask::class,'task_id');} public function dailyRecords():HasMany{return $this->hasMany(OperationAssetDailyRecord::class,'asset_id');} public function reviews():HasMany{return $this->hasMany(OperationTaskReview::class,'target_id')->where('target_type','asset');} public function rectifications():HasMany{return $this->hasMany(OperationTaskRectification::class,'asset_id');} public function deliveryRounds():HasMany{return $this->hasMany(OperationTaskDeliveryRound::class,'asset_id');} }
