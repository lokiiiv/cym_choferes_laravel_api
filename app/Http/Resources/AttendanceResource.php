<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class AttendanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $tipo = '';
        if ($this->att_type == 'ENTRADA'){
            $tipo = 'Entrada de planta.';
        } elseif ($this->att_type == 'ENTRADA_BASCULA'){
            $tipo = 'Entrada a báscula';
        } elseif ($this->att_type == 'SALIDA_BASCULA'){
            $tipo = 'Salida de báscula.';
        } elseif ($this->att_type == 'INICIO_DESENLONE') {
            $tipo = 'Inicio de desenlone.';
        } elseif ($this->att_type == 'SALIDA_DESENLONE') {
            $tipo = 'Salida de desenlone.';
        } elseif ($this->att_type == 'INICIO_MUESTREO') {
            $tipo = 'Inicio de muestro.';
        } elseif ($this->att_type == 'SALIDA_MUESTREO') {
            $tipo = 'Salida de muestreo.';
        } elseif ($this->att_type == 'INICIO_DESCARGA') {
            $tipo = 'Inicio de descarga.';
        } elseif ($this->att_type == 'SALIDA_DESCARGA') {
            $tipo = 'Salida de descarga';
        } elseif ($this->att_type == 'ENTRADA_BASCULA_2') {
            $tipo = 'Segunda entrada a báscula.';
        } elseif ($this->att_type == 'SALIDA_BASCULA_2') {
            $tipo = 'Segunda salida de báscula.';
        } elseif ($this->att_type == 'SALIDA') {
            $tipo = 'Salida de planta.';
        }

        return [
            'idAttendance' => $this->idAttendance,
            'date_time' =>  Carbon::parse($this->date_time)->isoFormat('MMM Do YYYY, h:mm:ss a'),
            'tipo' => $tipo
        ];
    }
}
