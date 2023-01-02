<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Carbon;

class VigenciaQuiz extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vigencia:quiz';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verificar la vigencia del ultimo cuestionario realizado, debe realizarse cada 30 días.';

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
        //Verificar la fecha en que los choferes contestaron su ultimo quiz o cuestionario
        $choferes = User::select('idUser', 'clave', 'nombreCompleto', 'lastQuizDate', 'estatus')
            ->where([['rol', 'chofer'], ['contestarQuiz', true]])
            ->get();

        foreach($choferes as $chofer) {
            if($chofer['estatus'] != 'vetado') {
                if($chofer['lastQuizDate'] != null){
                    //Sumarle 30 dias a la fecha en que contesto su ultimo cuestionario
                    $expiracionQuiz = Carbon::parse($chofer['lastQuizDate'])->addDays(30)->format('Y-m-d');
                    //Obtener la fecha actual
                    $fechaActual = Carbon::parse(Carbon::now()->format('Y-m-d'));
    
                    //Si la fecha actual es mayor o igual a la fecha de expiración, resetar el valor de que ya contesto el quiz
                    if($fechaActual->greaterThanOrEqualTo($expiracionQuiz)){
                        User::findOrFail($chofer['idUser'])
                            ->update(['estatus' => 'incompleto', 'contestarQuiz' => false, 'lastQuizDate' => null]);
                    }
                }
            }
            
        }
    }
}
