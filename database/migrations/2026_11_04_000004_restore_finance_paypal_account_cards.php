<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts must be entered by users or derived from system accounts in the current environment.
    }

    public function down(): void {}
};
