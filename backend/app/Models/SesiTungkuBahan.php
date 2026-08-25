<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rincian bahan mentah per grade dalam satu sesi tungku. */
class SesiTungkuBahan extends Model
{
    protected $table = 'sesi_tungku_bahan';

    protected $fillable = ['sesi_tungku_id', 'grade', 'kg'];

    protected function casts(): array
    {
        return [
            'grade' => Grade::class,
            'kg' => 'float',
        ];
    }

    /** @return BelongsTo<SesiTungku, $this> */
    public function sesiTungku(): BelongsTo
    {
        return $this->belongsTo(SesiTungku::class);
    }
}
