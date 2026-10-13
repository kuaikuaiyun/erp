<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理员封禁:admin_users 增加 status 列(1=正常,0=禁用)。
 * 存量行默认 1(不改变既有行为);已装系统发布新迁移后执行 migrate 即可。
 */
class AddStatusToAdminUsersTable extends Migration
{
    public function getConnection()
    {
        return config('admin.database.connection') ?: config('database.default');
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table(config('admin.database.users_table'), function (Blueprint $table) {
            $table->tinyInteger('status')->default(1)->after('password');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table(config('admin.database.users_table'), function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
}


