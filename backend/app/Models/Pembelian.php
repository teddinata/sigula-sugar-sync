<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grade;
use App\Enums\StatusPembayaran;
use Database\Factories\PembelianFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pembelian extends Model
{
    /** @use HasFactory<PembelianFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pembelian';

    protected $fillable = [
        'nomor_kwitansi',
        'tanggal',
        'petani_id',
        'pengepul_id',
        'grade',
        'grade_harga_id',
        'kilogram',
        'harga_per_kg',
        'total',
        'total_sebelum_bulat',
        'status_pembayaran',
        'catatan',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'grade' => Grade::class,
            'kilogram' => 'float',
            'harga_per_kg' => 'float',
            'total' => 'float',
            'total_sebelum_bulat' => 'float',
            'status_pembayaran' => StatusPembayaran::class,
        ];
    }

    /** @return BelongsTo<Petani, $this> */
    public function petani(): BelongsTo
    {
        return $this->belongsTo(Petani::class);
    }

    /** @return BelongsTo<Pengepul, $this> */
    public function pengepul(): BelongsTo
    {
        return $this->belongsTo(Pengepul::class);
    }

    /** @return BelongsTo<GradeHarga, $this> */
    public function gradeHarga(): BelongsTo
    {
        return $this->belongsTo(GradeHarga::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphMany<KartuStok, $this> */
    public function mutasiStok(): MorphMany
    {
        return $this->morphMany(KartuStok::class, 'referensi');
    }

    public function scopeRentang(Builder $query, ?string $dari, ?string $sampai): Builder
    {
        return $query
            ->when($dari, fn (Builder $q, string $d): Builder => $q->whereDate('tanggal', '>=', $d))
            ->when($sampai, fn (Builder $q, string $s): Builder => $q->whereDate('tanggal', '<=', $s));
    }

    public function scopeGrade(Builder $query, ?Grade $grade): Builder
    {
        return $grade === null ? $query : $query->where('grade', $grade->value);
    }
}
