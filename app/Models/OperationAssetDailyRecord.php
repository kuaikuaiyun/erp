<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OperationAssetDailyRecord extends Model { protected $guarded=[]; public function __construct(array $a=[]){$this->setConnection(config('admin.database.connection')?:config('database.default'));parent::__construct($a);} protected function casts():array{return['data_date'=>'date','spend_usd'=>'decimal:2','roi'=>'decimal:6','version_history'=>'array'];} }
