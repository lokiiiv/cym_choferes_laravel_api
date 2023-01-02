<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class QuizDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'idQuiz' => $this->idQuiz,
            'nombre' => $this->nombre,
            'puntajeTotal' => $this->puntajeTotal,
            'preguntas' => QuestionResource::collection($this->questions)->shuffle()
        ];
    }

    public function with($request) {
        return [
            'res' => true,
        ];
    }
}
