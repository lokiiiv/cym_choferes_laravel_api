<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Carbon\Carbon;

class VigenciaDocumentos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vigencia:documentos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verificar la vigencia de las fechas de los documentos de los choferes';

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
        //Ejecutar una consulta a todos los choferes y obtener sus documentos cuyo tipo sean fecha
        //Verificar la vigencia de las fecha
        $data = User::select('idUser', 'clave', 'nombreCompleto', 'estatus')
            ->where('rol', 'chofer')
            ->has('documents')
            ->with(['documents' => function ($query) {
                $query->select('document.idDocs', 'document.nombre')
                    ->where([['tipo', 'date'], ['verificarVigencia', true]]);
            }])->get();

        foreach ($data as $user) {
            if ($user['estatus'] != 'vetado') {
                //Obtener las fechas de los documentos
                $docs = $user['documents'];
                foreach ($docs as $doc) {
                    //Obtener la fecha del documento
                    $fechaDocumento = Carbon::parse($doc['pivot']['valor'])->format('Y-m-d');
                    //Obtener la fecha actual
                    $fechaActual = Carbon::parse(Carbon::now()->format('Y-m-d'));

                    //Si la fecha actual es mayor o igual a la fecha del documento
                    //Quiere decir que expiro la vigencia, por lo tanto actualizar el estatus del usuario a incompleto
                    //Y poner en false que ya subio su documentación completa
                    if ($fechaActual->greaterThanOrEqualTo($fechaDocumento)) {
                        User::findOrFail($user['idUser'])
                            ->update(['estatus' => 'incompleto', 'subirDocs' => false]);
                        break;
                    }
                }
            }
        }
    }
}
