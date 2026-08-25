<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatusPenderes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris = satu status penderes yang disandang seorang petani. */
class PetaniStatus extends Model
{
    protected $table = 'petani_status';

    protected $fillable = ['petani_id', 'kode'];

    protected function casts(): array
    {
        return ['kode' => StatusPenderes::class];
    }

    /** @return BelongsTo<Petani, $this> */
    public function petani(): BelongsTo
    {
        return $this->belongsTo(Petani::class);
    }
}
