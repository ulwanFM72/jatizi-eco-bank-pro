<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rincian setoran: satu transaksi bisa berisi beberapa jenis sampah, masing-masing dengan berat sendiri.
        Schema::create('setoran_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setoran_id')->constrained('setoran')->cascadeOnDelete();
            $table->foreignId('jenis_sampah_id')->constrained('jenis_sampah')->restrictOnDelete();
            $table->unsignedInteger('berat_gram');

            // Snapshot aturan poin jenis ini saat dicatat. NULL = jenis ini tidak bernilai poin.
            $table->unsignedInteger('aturan_gram')->nullable();
            $table->unsignedInteger('aturan_poin')->nullable();

            // Poin item ini menurut buku besar kelas dan buku besar pribadi penyetor.
            $table->unsignedInteger('poin')->default(0);
            $table->unsignedInteger('poin_pribadi')->nullable();
            $table->timestamps();

            $table->index(['setoran_id', 'id']);
            $table->index('jenis_sampah_id');
        });

        // Buku besar per pemilik (kelas atau akun) per jenis sampah: sisa berat dan poin tiap jenis dipisah.
        Schema::create('saldo_jenis', function (Blueprint $table) {
            $table->id();
            $table->string('pemilik_tipe', 10); // kelas | user
            $table->unsignedBigInteger('pemilik_id');
            $table->foreignId('jenis_sampah_id')->constrained('jenis_sampah')->cascadeOnDelete();
            $table->unsignedBigInteger('total_gram')->default(0);
            $table->unsignedInteger('sisa_gram')->default(0);
            $table->unsignedBigInteger('total_poin')->default(0);
            $table->timestamps();

            $table->unique(['pemilik_tipe', 'pemilik_id', 'jenis_sampah_id'], 'saldo_jenis_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldo_jenis');
        Schema::dropIfExists('setoran_item');
    }
};
