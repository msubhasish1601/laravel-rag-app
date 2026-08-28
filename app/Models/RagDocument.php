<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RagDocument extends Model
{
    protected $fillable = ['title', 'file_path', 'category', 'status'];

    public function chunks()
    {
        return $this->hasMany(DocumentChunk::class);
    }
}
