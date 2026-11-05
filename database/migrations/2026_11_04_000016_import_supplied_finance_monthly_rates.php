<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Monthly rates must be configured in each environment, never copied from development workbooks.
    }

    public function down(): void {}
};
