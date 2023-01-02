<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizQuestion extends Model
{
    use HasFactory;

    protected $table = 'quiz_question';
    public $timestamps = false;
    protected $primaryKey = 'idQuestion';

    protected $fillable = [
        'tipo',
        'puntaje',
        'contenido',
        'FK_idQuiz'
    ];

    //Relacion uno a muchos inversa con Quiz
    public function quiz() {
        return $this->belongsTo(Quiz::class, 'FK_idQuiz');
    }

    //Relacion de uno a muchos con QuizAnswer
    public function answers() {
        return $this->hasMany(QuizAnswer::class, 'FK_idQuestion');
    }
}
