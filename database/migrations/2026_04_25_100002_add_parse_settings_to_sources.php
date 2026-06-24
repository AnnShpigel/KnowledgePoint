<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет JSON-поле parse_settings в таблицу sources.
 *
 * Поле хранит конфигурацию, специфичную для каждого парсера.
 * Благодаря JSON-типу расширение настроек не требует новых миграций.
 *
 * Пример для OAI-PMH источника:
 * {
 *   "oai_endpoint":      "https://cyberleninka.ru/oai",
 *   "metadata_prefix":   "oai_dc",
 *   "oai_set":           null,
 *   "batch_limit":       100,
 *   "scrape_pages":      true,
 *   "scrape_delay_ms":   800,
 *   "request_delay_ms":  500
 * }
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
        Schema::table('sources', function (Blueprint $table): void {
            $table->json('parse_settings')->nullable()->after('api_key');
        });
    }

    /**
     * Откатить миграцию.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn('parse_settings');
        });
    }
};
