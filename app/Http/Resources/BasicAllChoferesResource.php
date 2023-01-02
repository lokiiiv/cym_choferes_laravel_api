<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class BasicAllChoferesResource extends JsonResource
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
            'nombreCompleto' => Str::upper($this->nombreCompleto),
            'empresaTransportista' => Str::upper($this->empresaTransportista),
            'estatus' => Str::upper($this->estatus),
            'foto' => $this->getProfilePictureUrlAttribute()
        ];
    }

    public function with($request) {
        return [
            'res' => true,
        ];
    }
}
