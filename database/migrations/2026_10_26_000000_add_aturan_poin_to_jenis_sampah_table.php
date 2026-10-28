<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenis_sampah', function (Blueprint $table) {
            // Hanya sampah an-organik daur ulang yang bernilai poin; tiap jenis punya aturan sendiri.
            $table->boolean('bernilai_poin')->default(false)->after('kategori');
            $table->unsignedInteger('gram_per_unit')->nullable()->after('bernilai_poin');
            $table->unsignedInteger('poin_per_unit')->nullable()->after('gram_per_unit');
        });

        // Jenis an-organik yang sudah ada tetap bernilai poin dengan aturan lama (1.000 g = 5 poin).
        DB::table('jenis_sampah')
            ->where('kategori', 'anorganik')
            ->update(['bernilai_poin' => true, 'gram_per_unit' => 1000, 'poin_per_unit' => 5]);
    }

    public function down(): void
    {
        Schema::table('jenis_sampah', function (Blueprint $table) {
            $table->dropColumn(['bernilai_poin', 'gram_per_unit', 'poin_per_unit']);
        });
    }
};
