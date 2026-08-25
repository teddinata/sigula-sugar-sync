<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grade;
use App\Enums\StatusSesi;
use Database\Factories\SesiTungkuFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SesiTungku extends Model
{
    /** @use HasFactory<SesiTungkuFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'sesi_tungku';

    protected $fillable = [
        'tanggal',
        'kode_tungku',
        'grade',
        'kg_bahan_mentah',
        'karyawan_1_id',
        'karyawan_2_id',
        'kg_kristal_total',
        'kg_brondol_total',
        'rendemen',
        'status',
        'selesai_pada',
        'catatan',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'grade' => Grade::class,
            'kg_bahan_mentah' => 'float',
            'kg_kristal_total' => 'float',
            'kg_brondol_total' => 'float',
            'rendemen' => 'float',
            'status' => StatusSesi::class,
            'selesai_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<Karyawan, $this> */
    public function karyawan1(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_1_id');
    }

    /** @return BelongsTo<Karyawan, $this> */
    public function karyawan2(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_2_id');
    }

    /**
     * Rincian bahan mentah per grade. Satu sesi bisa memakai campuran
     * beberapa grade; kolom `kg_bahan_mentah` adalah totalnya.
     *
     * @return HasMany<SesiTungkuBahan, $this>
     */
    public function bahan(): HasMany
    {
        return $this->hasMany(SesiTungkuBahan::class)->orderBy('id');
    }

    /** @return HasMany<ProduksiKaryawan, $this> */
    public function porsiKaryawan(): HasMany
    {
        return $this->hasMany(ProduksiKaryawan::class);
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

    public function scopeSelesai(Builder $query): Builder
    {
        return $query->where('status', StatusSesi::SELESAI->value);
    }

    public function scopeRentang(Builder $query, ?string $dari, ?string $sampai): Builder
    {
        return $query
            ->when($dari, fn (Builder $q, string $d): Builder => $q->whereDate('tanggal', '>=', $d))
            ->when($sampai, fn (Builder $q, string $s): Builder => $q->whereDate('tanggal', '<=', $s));
    }

    public function totalHasil(): float
    {
        return (float) ($this->kg_kristal_total ?? 0) + (float) ($this->kg_brondol_total ?? 0);
    }

    /** Rendemen = (kristal + brondol) / bahan mentah × 100. */
    public function hitungRendemen(): ?float
    {
        if ($this->status !== StatusSesi::SELESAI || (float) $this->kg_bahan_mentah <= 0.0) {
            return null;
        }

        return round($this->totalHasil() / (float) $this->kg_bahan_mentah * 100, 2);
    }
}
