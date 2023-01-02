<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AuthResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */

    public $accessToken;

    public function __construct($resource, $accessToken)
    {   
        parent::__construct($resource);
        $this->accessToken = $accessToken;
    }

    public function toArray($request)
    {
        return [
            'id' => $this->idUser,
            'rol' => $this->rol, 
            'nombreCompleto' => Str::of($this->nombreCompleto)->upper(),
            'accessToken' => $this->accessToken,
        ];
    }

    public function with($request) {
        return [
            'res' => true,
        ];
    }
}
