<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrustedSite extends Model
{
    use HasFactory;
    
    protected $fillable = ['name', 'url', 'is_active'];

    public function indexedPages(): HasMany
    {
        return $this->hasMany(IndexedPage::class);
    }
}