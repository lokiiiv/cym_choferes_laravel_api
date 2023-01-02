<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizAnswer extends Model
{
    use HasFactory;

    protected $table = 'quiz_answer';
    public $timestamps = false;
    protected $primaryKey = 'idAnswer';

    protected $fillable = [
        'contenido',
        'correcto',
        'FK_idQuiz',
        'FK_idQuestion'
    ];

    //Relacion uno a muchos inversa con QuizQuestion
    public function question() {
        return $this->belongsTo(QuizQuestion::class, 'FK_idQuestion');
    }
}
