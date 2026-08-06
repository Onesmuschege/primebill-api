<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Product extends Model
{
    use HasFactory, BelongsToTenant;

    // Minimal fillable fields used by tests - extend as needed
    protected $fillable = [
        'name',
        'sku',
        'price',
        'description',
    ];
}
