<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatusGaji;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GajiMingguan extends Model
{
    protected $table = 'gaji_mingguan';

    protected $fillable = [
        'karyawan_id',
        'periode_senin',
        'periode_jumat',
        'kg_kristal',
        'kg_brondol',
        'hari_kerja',
        'upah_kristal',
        'upah_brondol',
        'uang_makan',
        'total',
        'total_sebelum_bulat',
        'status',
        'dibayar_pada',
        'dibayar_oleh',
    ];

    protected function casts(): array
    {
        return [
            'periode_senin' => 'date:Y-m-d',
            'periode_jumat' => 'date:Y-m-d',
            'kg_kristal' => 'float',
            'kg_brondol' => 'float',
            'hari_kerja' => 'integer',
            'upah_kristal' => 'float',
            'upah_brondol' => 'float',
            'uang_makan' => 'float',
            'total' => 'float',
            'total_sebelum_bulat' => 'float',
            'status' => StatusGaji::class,
            'dibayar_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<Karyawan, $this> */
    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function pembayar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibayar_oleh');
    }

    public function scopePeriode(Builder $query, string $senin): Builder
    {
        return $query->whereDate('periode_senin', $senin);
    }

    public function sudahDibayar(): bool
    {
        return $this->status === StatusGaji::SUDAH_DIBAYAR;
    }
}
