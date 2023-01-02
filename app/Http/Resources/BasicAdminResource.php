<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class BasicAdminResource extends JsonResource
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
            "clave" => $this->clave,
            "nombreCompleto" => Str::of($this->nombreCompleto)->upper(),
            "rol" => Str::of($this->rol)->upper(),
            "foto" => $this->getProfilePictureUrlAttribute(),
        ];
    }

    public function with($request) {
        return [
            'res' => true,
        ];
    }
}
