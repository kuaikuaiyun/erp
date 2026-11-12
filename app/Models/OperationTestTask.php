<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class OperationTestTask extends Model { protected $guarded=[]; public function __construct(array $a=[]){$this->setConnection(config('admin.database.connection')?:config('database.default'));parent::__construct($a);} protected function casts():array{return['submitted_at'=>'datetime','version_history'=>'array'];} public function assets():HasMany{return $this->hasMany(OperationTaskAsset::class,'task_id');} public function reviews():HasMany{return $this->hasMany(OperationTaskReview::class,'target_id')->where('target_type','task');} public function rectifications():HasMany{return $this->hasMany(OperationTaskRectification::class,'task_id');} }
