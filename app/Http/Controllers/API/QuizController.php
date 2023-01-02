<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuizDetailResource;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;

class QuizController extends ApiController
{
    public function __construct()
    {
        $this->middleware('jwt.verify');
    }
    
    //Obtener el detalle de un Quiz
    public function detailQuiz($id) {
        
        $quizDetail = Quiz::with(['questions', 'questions.answers'])->where('idQuiz', $id)->firstOrFail();
        return (new QuizDetailResource($quizDetail))->additional([
            'msg' => null
        ])->response()->setStatusCode(200);
    }

    //Metodo para verificar si la respuesta es correcta
    public function isCorrectAnswer(Request $request) {
        $request->validate([
            'idQuestion' => 'required',
            'idUserAnswer' => 'required'
        ]);

        $idQuestion = $request->idQuestion;
        $idUserAnswer = $request->idUserAnswer;

        $correcto = QuizAnswer::select('idAnswer')
                                ->where([['FK_idQuestion', $idQuestion], ['correcto', 1]])
                                ->firstOrFail();
        
        $idAnswerCorrect = $correcto['idAnswer'];
        //Si la respuesta correcta es igual a la respuesta del usuario
        if($idUserAnswer == $idAnswerCorrect){
            return $this->successResponse(array('correcto' => true, 'idAnswerCorrect' => $idAnswerCorrect));
        } else {
            return $this->successResponse(array('correcto' => false, 'idAnswerCorrect' => $idAnswerCorrect));
        }     
    }
}
