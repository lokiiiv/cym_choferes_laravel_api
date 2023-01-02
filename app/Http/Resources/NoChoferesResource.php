<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class NoChoferesResource extends JsonResource
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
            'idUser' => $this->idUser,
            'clave' => $this->clave,
            'nombreCompleto' => Str::upper($this->nombreCompleto),
            'rol' => Str::upper($this->rol),
            'foto' => $this->getProfilePictureUrlAttribute()
        ];
    }
}
