<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class StatusChoferResource extends JsonResource
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
            'nombreCompleto' => Str::upper($this->nombreCompleto),
            'edad' => $this->edad,
            'estadoProcedencia' => Str::upper($this->estadoProcedencia),
            'foto' => $this->getProfilePictureUrlAttribute(),
            'empresaTransportista' => Str::upper($this->empresaTransportista),
            'estatus' => Str::upper($this->estatus)
        ];
    }

    public function with($request) {
        return [
            'res' => true,
        ];
    }
}
