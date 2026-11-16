<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DingTalkCompany extends Model
{
    use SoftDeletes;
    protected $table = 'dingtalk_companies';
    protected $guarded = ['id'];
    protected $hidden = ['app_secret', 'callback_token', 'callback_aes_key', 'directory_state'];
    protected $casts = [
        'enabled'=>'boolean', 'version'=>'integer', 'app_secret'=>'encrypted', 'callback_token'=>'encrypted',
        'callback_aes_key'=>'encrypted', 'directory_state'=>'array', 'directory_synced_at'=>'datetime',
    ];
}
