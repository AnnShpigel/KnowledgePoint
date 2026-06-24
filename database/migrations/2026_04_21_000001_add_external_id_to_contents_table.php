<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Идентификатор во внешней системе: DOI, ISBN, OAI-PMH identifier, arXiv ID
            $table->string('external_id')->nullable()->after('url');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};
