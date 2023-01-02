<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    use HasFactory;

    protected $table = 'quiz';
    public $timestamps = false;
    protected $primaryKey = 'idQuiz';

    protected $fillable = [
        'nombre',
        'fechaCreacion',
        'puntajeTotal'
    ];

    //Relacion de uno a muchos con QuizQuestion
    public function questions() {
        return $this->hasMany(QuizQuestion::class, 'FK_idQuiz');
    }

}
