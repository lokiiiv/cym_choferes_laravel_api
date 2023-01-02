<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Carbon;

class ActualizarEdades extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'actualizar:edades';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verificar constantemente las fechas de nacimiento de los choferes para actualizar sus edades si es necesario.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $choferes = User::select('idUser', 'clave', 'nombreCompleto', 'fechaNacimiento', 'edad')
            ->where('rol', 'chofer')
            ->get();

        foreach($choferes as $chofer) {
            //Obtener la edad que el chofer deberia tener conforme a su fecha de nacimiento
            $edadReal = Carbon::parse($chofer['fechaNacimiento'])->age;

            //Comparar con la edad que tiene registrada en la base de datos
            if($chofer['edad'] != $edadReal) {
                //si la edad real no es igual a la que esta en la base de datos, alctualizar la edad de la base de datos por la real
                User::findOrFail($chofer['idUser'])
                    ->update(['edad' => $edadReal]);
            }
        }
    }
}
