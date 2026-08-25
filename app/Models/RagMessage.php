<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RagMessage extends Model
{
    // Add raw_chunks to fillable
    protected $fillable = ['role', 'content', 'source_info', 'raw_chunks'];

    // Cast the JSON column to an array automatically
    protected $casts = [
        'raw_chunks' => 'array',
    ];
}
