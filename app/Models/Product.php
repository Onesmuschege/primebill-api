<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    // Minimal fillable fields used by tests - extend as needed
    protected $fillable = [
        'name',
        'sku',
        'price',
        'description',
    ];
}
