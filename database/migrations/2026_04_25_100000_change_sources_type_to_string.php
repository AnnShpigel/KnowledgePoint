<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Изменяет колонку type таблицы sources с ENUM на VARCHAR.
 *
 * Причина: добавление нового протокола agрегации «oai-pmh» невозможно
 * без изменения типа колонки. Переход к VARCHAR делает список протоколов
 * открытым для расширения без дополнительных миграций в будущем.
 *
 * Допустимые значения: 'api', 'oai-pmh', 'scraper'
 */
return new class extends Migration
{
    /**
     * Применить миграцию: заменить ENUM на VARCHAR(30).
     *
     * @return void
     */
    public function up(): void
    {
        // Явный SQL используется намеренно: Doctrine DBAL не поддерживает
        // изменение ENUM-колонок без полного пересоздания таблицы,
        // что приводит к блокировке при наличии данных.
        DB::statement(
            "ALTER TABLE sources MODIFY COLUMN type VARCHAR(30) NOT NULL DEFAULT 'scraper'"
        );
    }

    /**
     * Откатить миграцию: вернуть ENUM с исходными значениями.
     *
     * @return void
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE sources MODIFY COLUMN type ENUM('api', 'scraper') NOT NULL DEFAULT 'scraper'"
        );
    }
};
