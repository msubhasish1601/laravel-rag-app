<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class DocumentChunk extends Model
{
    use HasNeighbors; // Enables the nearest-neighbor similarity search

    protected $fillable = ['rag_document_id', 'chunk_index', 'content', 'embedding'];

    protected $casts = [
        'embedding' => Vector::class, // Casts the DB vector into a PHP object
    ];

    public function document()
    {
        return $this->belongsTo(RagDocument::class, 'rag_document_id');
    }
}
