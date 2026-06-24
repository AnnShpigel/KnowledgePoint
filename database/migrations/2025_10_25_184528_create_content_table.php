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
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('author')->nullable();
            $table->enum('type', ['article', 'video', 'course', 'documentation', 'book'])->default('article');
            $table->string('url')->unique(); // Ссылка на материал
            $table->string('language', 10)->default('en'); // ru, en
            $table->date('published_at')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending'); // Статус модерации
            $table->text('tags')->nullable(); // JSON массив тегов
            $table->integer('views')->default(0);
            $table->timestamps();

            // Индексы для быстрого поиска
            $table->index(['type', 'status']);
            $table->index('published_at');
            $table->fullText(['title', 'description']); // Полнотекстовый поиск
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
