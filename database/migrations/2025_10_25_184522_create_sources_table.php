<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('url'); // Базовый URL источника
            $table->string('api_endpoint')->nullable(); // Эндпоинт API, если есть
            $table->string('api_key')->nullable(); // Ключ API (зашифрован)
            $table->enum('type', ['api', 'scraper'])->default('scraper'); // Тип парсинга
            $table->boolean('is_active')->default(true);
            $table->integer('parse_interval')->default(86400); // Интервал парсинга в секундах (по умолчанию 24 часа)
            $table->timestamp('last_parsed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
