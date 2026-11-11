<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OperationTaskRecap extends Model { protected $guarded=[]; public function __construct(array $a=[]){$this->setConnection(config('admin.database.connection')?:config('database.default'));parent::__construct($a);} protected function casts():array{return['data_date'=>'date','media_items'=>'array','question_answers'=>'array','version_history'=>'array','submitted_at'=>'datetime','last_rejected_at'=>'datetime','total_revenue_usd'=>'decimal:2','total_spend_usd'=>'decimal:2'];} }
