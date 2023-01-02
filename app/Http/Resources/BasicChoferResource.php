<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BasicChoferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        setlocale(LC_ALL,"es_ES"); 
        $proximoQuiz = $this->lastQuizDate ? Carbon::parse($this->lastQuizDate)->addDays(30) : null;
        return [
            'clave' => $this->clave,
            'nombreCompleto' => Str::of($this->nombreCompleto)->upper(),
            'foto' => $this->getProfilePictureUrlAttribute(),
            'estatus' => Str::of($this->estatus)->upper(),
            'lastQuizDate' => $this->lastQuizDate ? Carbon::parse($this->lastQuizDate)->isoFormat('ddd D MMM YYYY') : null,
            'proximoQuiz' => $this->lastQuizDate ? $proximoQuiz->isoFormat('ddd D MMM YYYY') : null,
            'diasRestantes' => $this->lastQuizDate ? Carbon::now()->diffInDays($proximoQuiz) : null
        ];
    }

    public function with($request) {
        return [
            'res' => true,
        ];
    }
}
