<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('logistics_paypal_accounts')) {
            $schema->table('logistics_paypal_accounts', function (Blueprint $table) use ($schema): void {
                if (!$schema->hasColumn('logistics_paypal_accounts', 'last_imported_at')) {
                    $table->dateTime('last_imported_at')->nullable()->index()->after('is_enabled');
                }
                if (!$schema->hasColumn('logistics_paypal_accounts', 'last_import_from')) {
                    $table->dateTime('last_import_from')->nullable()->after('last_imported_at');
                }
                if (!$schema->hasColumn('logistics_paypal_accounts', 'last_import_to')) {
                    $table->dateTime('last_import_to')->nullable()->after('last_import_from');
                }
                if (!$schema->hasColumn('logistics_paypal_accounts', 'last_import_status')) {
                    $table->string('last_import_status', 30)->nullable()->index()->after('last_import_to');
                }
                if (!$schema->hasColumn('logistics_paypal_accounts', 'last_import_message')) {
                    $table->text('last_import_message')->nullable()->after('last_import_status');
                }
            });

            $database = DB::connection($connection);
            if ($schema->hasColumn('logistics_paypal_accounts', 'last_synced_at')) {
                $database->table('logistics_paypal_accounts')->update([
                    'last_imported_at' => DB::raw('last_synced_at'),
                ]);
            }
            if ($schema->hasColumn('logistics_paypal_accounts', 'last_sync_from')) {
                $database->table('logistics_paypal_accounts')->update([
                    'last_import_from' => DB::raw('last_sync_from'),
                ]);
            }
            if ($schema->hasColumn('logistics_paypal_accounts', 'last_sync_to')) {
                $database->table('logistics_paypal_accounts')->update([
                    'last_import_to' => DB::raw('last_sync_to'),
                ]);
            }
            if ($schema->hasColumn('logistics_paypal_accounts', 'last_sync_status')) {
                $database->table('logistics_paypal_accounts')->update([
                    'last_import_status' => DB::raw('last_sync_status'),
                ]);
            }
            if ($schema->hasColumn('logistics_paypal_accounts', 'last_sync_message')) {
                $database->table('logistics_paypal_accounts')->update([
                    'last_import_message' => DB::raw('last_sync_message'),
                ]);
            }

            $obsolete = array_values(array_filter([
                'client_id',
                'client_secret',
                'access_token',
                'token_expires_at',
                'granted_scopes',
                'last_sync_started_at',
                'last_synced_at',
                'last_sync_from',
                'last_sync_to',
                'last_sync_status',
                'last_sync_message',
            ], static fn (string $column): bool => $schema->hasColumn('logistics_paypal_accounts', $column)));

            if ($obsolete !== []) {
                $schema->table('logistics_paypal_accounts', function (Blueprint $table) use ($obsolete): void {
                    $table->dropColumn($obsolete);
                });
            }
        }

        if (!$schema->hasTable('logistics_paypal_imports')) {
            $schema->create('logistics_paypal_imports', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_paypal_account_id')->index();
                $table->string('upload_id', 64)->unique();
                $table->string('merchant_id', 100)->index();
                $table->string('environment', 20)->nullable();
                $table->string('source_file', 255)->nullable();
                $table->char('payload_sha256', 64)->index();
                $table->string('storage_path', 500);
                $table->dateTime('period_from')->nullable();
                $table->dateTime('period_to')->nullable();
                $table->dateTime('generated_at')->nullable();
                $table->string('status', 30)->index();
                $table->unsignedInteger('transaction_count')->default(0);
                $table->unsignedInteger('refund_count')->default(0);
                $table->unsignedInteger('dispute_count')->default(0);
                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('unmatched_count')->default(0);
                $table->unsignedInteger('error_count')->default(0);
                $table->text('message')->nullable();
                $table->timestamps();

                $table->index(
                    ['logistics_paypal_account_id', 'created_at'],
                    'logistics_paypal_import_account_created_index',
                );
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('logistics_paypal_imports')) {
            $schema->drop('logistics_paypal_imports');
        }

        if (!$schema->hasTable('logistics_paypal_accounts')) {
            return;
        }

        $schema->table('logistics_paypal_accounts', function (Blueprint $table) use ($schema): void {
            if (!$schema->hasColumn('logistics_paypal_accounts', 'client_id')) {
                $table->string('client_id', 255)->nullable();
            }
            if (!$schema->hasColumn('logistics_paypal_accounts', 'client_secret')) {
                $table->text('client_secret')->nullable();
            }
            if (!$schema->hasColumn('logistics_paypal_accounts', 'access_token')) {
                $table->longText('access_token')->nullable();
            }
            if (!$schema->hasColumn('logistics_paypal_accounts', 'token_expires_at')) {
                $table->dateTime('token_expires_at')->nullable();
            }
            if (!$schema->hasColumn('logistics_paypal_accounts', 'granted_scopes')) {
                $table->text('granted_scopes')->nullable();
            }
            if (!$schema->hasColumn('logistics_paypal_accounts', 'last_synced_at')) {
                $table->dateTime('last_synced_at')->nullable();
            }
            if (!$schema->hasColumn('logistics_paypal_accounts', 'last_sync_status')) {
                $table->string('last_sync_status', 30)->nullable()->index();
            }
            if (!$schema->hasColumn('logistics_paypal_accounts', 'last_sync_message')) {
                $table->text('last_sync_message')->nullable();
            }
        });

        $schema->table('logistics_paypal_accounts', function (Blueprint $table) use ($schema): void {
            $columns = array_values(array_filter([
                'last_imported_at',
                'last_import_from',
                'last_import_to',
                'last_import_status',
                'last_import_message',
            ], static fn (string $column): bool => $schema->hasColumn('logistics_paypal_accounts', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
