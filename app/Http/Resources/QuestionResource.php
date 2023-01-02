<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
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
            'idQuestion' => $this->idQuestion,
            'tipo' => $this->tipo,
            'puntaje' => $this->puntaje,
            'contenido' => $this->contenido,
            'respuestas' => $this->answers()->select('idAnswer', 'contenido')->get()->shuffle()
        ];
    }
}
