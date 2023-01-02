<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DetailChoferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $arrayData = [
            'nombreCompleto' => Str::upper($this->nombreCompleto),
            'clave' => $this->clave,
            'rol' => Str::upper($this->rol),
            'fechaNacimiento' => Str::upper(Carbon::createFromFormat('Y-m-d', $this->fechaNacimiento)->format('d M Y')),
            'edad' => Str::upper(strval($this->edad) . ' años'),
            'fechaRegistro' => Str::upper(Carbon::createFromFormat('Y-m-d', $this->fechaRegistro)->format('d M Y')),
            'genero' => Str::upper($this->genero),
            'empresaTransportista' => Str::upper($this->empresaTransportista),
            'estadoProcedencia' => Str::upper($this->estadoProcedencia),
            'foto' => $this->getProfilePictureUrlAttribute(),
            'telefonoCelular' => $this->telefonoCelular,
            'telefonoContactoEmpresa' => $this->telefonoContactoEmpresa,
            'estatus' => Str::upper($this->estatus)
        ];
        ($this->verVideo == 1) ? $arrayData['verVideo'] = 'SI' : $arrayData['verVideo'] = 'NO';
        ($this->contestarQuiz == 1) ? $arrayData['contestarQuiz'] = 'SI' : $arrayData['contestarQuiz'] = 'NO';
        ($this->subirDocs == 1) ? $arrayData['subirDocs'] = 'SI' : $arrayData['subirDocs'] = 'NO';

        return $arrayData;
    }

    public function with($request) {
        return [
            'res' => true,
        ];
    }
}
