<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AttChoferResource extends JsonResource
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
            'edad' => Str::upper(strval($this->edad) . ' años'),
            'estadoProcedencia' => Str::upper($this->estadoProcedencia),
            'foto' => $this->getProfilePictureUrlAttribute(),
            'empresaTransportista' => Str::of($this->empresa)->upper(),
            'estatus' => Str::of($this->estatus)->upper()
        ];
    }

    public function with($request) {
        return [
            'res' => true,
        ];
    }
}
