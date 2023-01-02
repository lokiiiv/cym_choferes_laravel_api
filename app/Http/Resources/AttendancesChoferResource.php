<?php

namespace App\Http\Resources;

use Carbon\CarbonInterval;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class AttendancesChoferResource extends JsonResource
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
            'idUser' => $this->FK_idUser,
            'check_in_at' => Carbon::parse($this->check_in_at)->isoFormat('MMMM Do YYYY, h:mm:ss a'),
            'check_out_at' => $this->check_out_at != null ? Carbon::parse($this->check_out_at)->isoFormat('MMMM Do YYYY, h:mm:ss a') : 'Salida pendiente.',
            'total_time' => $this->total_time != null ? CarbonInterval::second($this->total_time)->cascade()->forHumans() : 'Tiempo total no disponible.'
        ];
    }

    public function with($request) {
        return [
            'res' => true,
        ];
    }
}
