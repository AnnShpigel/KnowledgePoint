<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Расширяем FULLTEXT индекс: добавляем поле author
        // Поиск по автору теперь ранжируется наравне с title/description
        DB::statement('ALTER TABLE contents DROP INDEX contents_title_description_fulltext');
        DB::statement('ALTER TABLE contents ADD FULLTEXT INDEX contents_search_fulltext (title, description, author)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contents DROP INDEX contents_search_fulltext');
        DB::statement('ALTER TABLE contents ADD FULLTEXT INDEX contents_title_description_fulltext (title, description)');
    }
};
