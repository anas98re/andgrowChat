<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndexedPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'trusted_site_id',
        'url',
        'title',
        'content',
        'embedding',
        'last_crawled_at'
    ];
    
    protected $casts = [
        'last_crawled_at' => 'datetime',
        'embedding' => 'array'
    ];

    public function trustedSite(): BelongsTo
    {
        return $this->belongsTo(TrustedSite::class);
    }
}