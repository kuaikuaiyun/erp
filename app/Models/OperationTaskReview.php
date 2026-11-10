<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OperationTaskReview extends Model { protected $guarded=[]; public function __construct(array $a=[]){$this->setConnection(config('admin.database.connection')?:config('database.default'));parent::__construct($a);} protected function casts():array{return['reviewed_at'=>'datetime'];} }
