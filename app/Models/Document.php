<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use HasFactory;

    protected $table = 'document';
    public $timestamps = false;
    protected $primaryKey = 'idDocs';

    protected $fillable = [
        'tipo',
        'nombre',
        'metadata',
        'verificarVigencia'
    ];

    public function users() {
        return $this->belongsToMany(User::class, 'user_document', 'idDocs', 'idUser')->withPivot('valor');
    }
}
