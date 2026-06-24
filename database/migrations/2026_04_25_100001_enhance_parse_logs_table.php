<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расширяет таблицу parse_logs для детальной статистики парсинга.
 *
 * Изменения:
 *  - Добавляет статус «running» (парсинг выполняется) к существующим success/failed.
 *  - Добавляет разбивку обработанных записей: новые, обновлённые, пропущенные.
 *  - Добавляет поля пагинации для отслеживания прогресса многостраничного парсинга.
 */
return new class extends Migration
{
    /**
     * Применить миграцию.
     *
     * @return void
     */
    public function up(): void
    {
        // Добавить 'running' к статусам: изменить ENUM через SQL
        DB::statement(
            "ALTER TABLE parse_logs MODIFY COLUMN status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running'"
        );

        Schema::table('parse_logs', function (Blueprint $table): void {
            // Детализация: сколько записей создано, обновлено, пропущено как дубли
            $table->unsignedInteger('items_new')->default(0)->after('items_parsed');
            $table->unsignedInteger('items_updated')->default(0)->after('items_new');
            $table->unsignedInteger('items_skipped')->default(0)->after('items_updated');

            // Прогресс многостраничного обхода (например, OAI-PMH resumptionToken)
            $table->unsignedInteger('pages_fetched')->default(0)->after('items_skipped');
            $table->unsignedInteger('total_records')->nullable()->after('pages_fetched');
        });
    }

    /**
     * Откатить миграцию.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('parse_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'items_new',
                'items_updated',
                'items_skipped',
                'pages_fetched',
                'total_records',
            ]);
        });

        DB::statement(
            "ALTER TABLE parse_logs MODIFY COLUMN status ENUM('success', 'failed') NOT NULL DEFAULT 'success'"
        );
    }
};
