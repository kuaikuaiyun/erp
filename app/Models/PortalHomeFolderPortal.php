<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalHomeFolderPortal extends Model
{
    protected $table = 'portal_home_folder_portals';

    protected $fillable = ['folder_id', 'portal_id', 'sort'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }
}
