<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RutasResource extends JsonResource
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
            'idRoute' => $this->idRoute,
            'folio' => $this->folio,
            'date_created' => Carbon::parse($this->date_created)->isoFormat('MMM Do YYYY, h:mm:ss a'),
            'para' => $this->att_for,
            'completed' => $this->completed,
            'nombre' => Str::upper($this->nombreCompleto),
            'ultima_asistencia' => AttendanceResource::collection($this->attendances) 
        ];
    }

    public function with($request) {
        return [
            'res' => true,
        ];
    }
}
