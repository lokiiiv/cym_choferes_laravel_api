<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Auth\User as Authenticatable;

use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory;

    protected $table = 'user';
    public $timestamps = false;
    protected $primaryKey = 'idUser';
    
    protected $fillable = [
        'clave',
        'password',
        'rol',
        'nombreCompleto',
        'fechaNacimiento',
        'edad',
        'fechaRegistro',
        'genero',
        'estadoProcedencia',
        'foto',
        'telefonoCelular',
        'estatus',
        'verVideo',
        'contestarQuiz',
        'subirDocs',
        'lastQuizDate'
    ];

    public function getProfilePictureUrlAttribute() : string {
        return Storage::disk('avatars')->url($this->foto);
    }

     /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    //Relacion de muchos a muchos con documents
    public function documents() {
        return $this->belongsToMany(Document::class, 'user_document', 'idUser', 'idDocs')->withPivot('valor');
    }
}
