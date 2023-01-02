<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use App\Models\Attendance;
use App\Models\Route;
use App\Models\User;
use App\Http\Resources\AttChoferResource;
use App\Http\Resources\RutasResource;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\AsistenciaExport;

class RouteController extends ApiController
{
    public function __construct()
    {
        $this->middleware('jwt.verify');
    }

    //Metodo para generara Excel con asistencias y tiempos de las rutas de cebada, malta o coproducto
    public function downloadAsistencias()
    {
        return Excel::download(new AsistenciaExport(), 'lista_asistencias.xlsx');
    }

    //Metodo para obtener las rutas actuales de cebada, malta o coproducto y sus asistencias 
    public function getAllRutas()
    {
        $datos = Route::select('idRoute', 'folio', 'date_created', 'att_for', 'completed', 'user.nombreCompleto')
            ->join('user', 'user.idUser', '=', 'route.FK_idUser')
            ->with(['attendances' => function ($q) {
                $q->orderBy('date_time', 'desc')->limit(1);
            }])
            ->orderBy('date_created', 'desc')
            ->get();

        return RutasResource::collection($datos)->additional([
            'res' => true,
            'msg' => null
        ])->response()->setStatusCode(200);
    }

    //Metodo para registrar una nueva ruta de descarga de cebada, así como su registro de entrada
    public function nuevaEntrada(Request $request)
    {
        $request->validate([
            'folio' => 'required',
            'att_for' => 'required',
            'empresa' => 'required',
            'FK_idUser' => 'required',
            'user_who_register' => 'required',
            'forzar' => 'required'
        ]);

        $folio = $request->folio;
        $att_type = 'ENTRADA';
        $att_for = $request->att_for;
        $empresa = $request->empresa;
        $idChofer = $request->FK_idUser;
        $user_who_register = $request->user_who_register;
        $forzar = $request->forzar;

        //Obtener la información del usuario y verificar su estatus
        $userData = User::select('nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'estatus')
            ->where([['idUser', $idChofer], ['rol', 'chofer']])
            ->firstOrFail();

        $estatus = $userData['estatus'];

        if ($estatus === 'activo') {
            //Si esta activo, registrar su nueva ruta en la base de datos
            $route = new Route();
            $route->folio = $folio;
            $route->date_created = date('Y-m-d H:i:s');
            $route->att_for = $att_for;
            $route->empresa = $empresa;
            $route->completed = false;
            $route->FK_idUser = $idChofer;
            if ($route->save()) {
                //Obtener la informacion del usuario o chofer ligada a esa ruta o recorrido
                $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
                    ->join('route', 'user.idUser', '=', 'route.FK_idUser')
                    ->where('route.idRoute', $route->idRoute)
                    ->firstOrFail();

                //Una ves registrada la nueva ruta, registrar su asistencia de entrada para esa ruta
                $route->attendances()->save(new Attendance([
                    'date_time' => date('Y-m-d H:i:s'),
                    'att_type' => $att_type,
                    'user_who_register' => $user_who_register
                ]));
                return (new AttChoferResource($chofer))->additional([
                    'msg' => 'Entrada registrada correctamente.',
                    'success' => true
                ])->response()->setStatusCode(200);
            } else {
                return $this->errorResponse('Error al registrar la entrada.', 500);
            }
        } else {
            if ($estatus === 'vetado') {
                //Si esta vetado, no registrar su entrada y devolver el mensaje
                return (new AttChoferResource($userData))->additional([
                    'msg' => 'Entrada no registrada, el chofer esta vetado de la planta.',
                    'success' => false
                ])->response()->setStatusCode(200);
            } else if ($estatus === 'incompleto') {

                if (!$forzar) {
                    //Si esta incompleto, es porque aun no sube su documentacion completa, video o cuestionario
                    return (new AttChoferResource($userData))->additional([
                        'msg' => 'Entrada no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                        'success' => false,
                        'permitir' => true
                    ])->response()->setStatusCode(200);
                } else {
                    //Registrar la entrada en caso de que se decida forzar si es que el usuario esta como incompleto
                    //Registrar su nueva ruta en la base de datos
                    $route = new Route();
                    $route->folio = $folio;
                    $route->date_created = date('Y-m-d H:i:s');
                    $route->att_for = $att_for;
                    $route->empresa = $empresa;
                    $route->completed = false;
                    $route->FK_idUser = $idChofer;
                    if ($route->save()) {
                        //Obtener la informacion del usuario o chofer ligada a esa ruta o recorrido
                        $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
                            ->join('route', 'user.idUser', '=', 'route.FK_idUser')
                            ->where('route.idRoute', $route->idRoute)
                            ->firstOrFail();

                        //Una ves registrada la nueva ruta, registrar su asistencia de entrada para esa ruta
                        $route->attendances()->save(new Attendance([
                            'date_time' => date('Y-m-d H:i:s'),
                            'att_type' => $att_type,
                            'user_who_register' => $user_who_register
                        ]));
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Entrada registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la entrada.', 500);
                    }
                }
            }
        }
    }

    //---------------------------------- DESCARGA DE CEBADA ----------------------------------------//

    //Metodo para registrar los movimientos en el primer intento
    public function registrarMovimientoCebada(Request $request, $id)
    {
        $request->validate([
            'idChofer' => 'required',
            'user_who_register' => 'required',
            'movimiento' => 'required'
        ]);

        $idRuta = $id;
        $movimiento = $request->movimiento;
        $user_who_register = $request->user_who_register;

        //Obtener la información del chofer ligado a esa ruta o recorrido
        $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
            ->join('route', 'user.idUser', '=', 'route.FK_idUser')
            ->where('route.idRoute', $idRuta)
            ->firstOrFail();

        //Comprobar si el id del usuario coincide con id del valor del codigo QR
        if ($chofer['idUser'] == $request->idChofer) {

            //Ejecutar un switch case para verificar que movimiento de cebada de tiene que registrar
            switch ($movimiento) {
                case "ENTRADA_BASCULA":
                    //Si el movimiento es una entrada a bascula

                    //Consultar el estatus del chofer
                    if ($chofer['estatus'] === 'activo') {
                        //Verificar si ya se registro este movimiento
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado una entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            //Verificar si ya se registro un movimiento posterior a este movimiento
                            //Si ya se registro un movimiento posterior, no registrar este movimiento
                            $hasSalidaBascula = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA')->first();
                            $hasInicioDesenlone = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_DESENLONE')->first();
                            $hasSalidaDesenlone = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_DESENLONE')->first();
                            $hasInicioMuestreo = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_MUESTREO')->first();
                            $hasSalidaMuestreo = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_MUESTREO')->first();
                            $hasInicioDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_DESCARGA')->first();
                            $hasSalidaDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_DESCARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaBascula || $hasInicioDesenlone || $hasSalidaDesenlone || $hasInicioMuestreo || $hasSalidaMuestreo || $hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaBascula) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la entrada de báscula debido a que ya se registro una salida de báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar una entrada a báscula en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                //Si no hay un movimiento posterior, verificar ahora cual fue el ultimo movimiento registrado
                                //Esto es debido a que los movimientos deben seguir un orden
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    //Verificar que su ultimo movimiento sea una ENTRADA
                                    if ($last_att['att_type'] != 'ENTRADA') {
                                        // Si no es una ENTRADA es probable que el usuario se haya equivocado al elegir el movimiento
                                        // O bien, el usuario haya olvidado registrar el movimiento ENTRADA
                                        // Devolver una respuesta con una variable adicional llamada olvido => true, para
                                        // indicar en el frontend una advertencia y mostrar un boton preguntando si desea registrar el movimiento de todas formas

                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        //Si es una ENTRADA, proceder a registrar este movimiento
                                        $attendance = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'ENTRADA_BASCULA',
                                            'user_who_register' => $user_who_register
                                        ]);
                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendance)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Entrada a báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {

                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        //Si el chofer esta vetado, no registrar el movimiento
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Entrada a báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        //Si el chofer esta como incompleto, es debido a que no hay subido su documentacion completa, video o quiz
                        //Devolver en la respuesta una variable permitir => true al frontend
                        //De este modo, se mostrar un boton mostrando una advertencia dando a elegir al usuario si se registra el movimiento o no
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Entrada a báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_BASCULA":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA')
                            ->first();

                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado una salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasInicioDesenlone = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_DESENLONE')->first();
                            $hasSalidaDesenlone = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_DESENLONE')->first();
                            $hasInicioMuestreo = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_MUESTREO')->first();
                            $hasSalidaMuestreo = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_MUESTREO')->first();
                            $hasInicioDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_DESCARGA')->first();
                            $hasSalidaDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_DESCARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasInicioDesenlone || $hasSalidaDesenlone || $hasInicioMuestreo || $hasSalidaMuestreo || $hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una salida de báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'ENTRADA_BASCULA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a la báscula.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        //Llega aqui porque la ultima asistencia si es una ENTRADA_BASCULA
                                        //Por ende registrar su entrada a salida de bascula
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_BASCULA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Salida de báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "INICIO_DESENLONE":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_DESENLONE')
                            ->first();

                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado el inicio a desenlone anteriormente, no puede volver a registrarlo nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalidaDesenlone = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_DESENLONE')->first();
                            $hasInicioMuestreo = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_MUESTREO')->first();
                            $hasSalidaMuestreo = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_MUESTREO')->first();
                            $hasInicioDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_DESCARGA')->first();
                            $hasSalidaDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_DESCARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaDesenlone || $hasInicioMuestreo || $hasSalidaMuestreo || $hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaDesenlone) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de desenlone debido a que ya se registro una salida de desenlone.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar el inicio de desenlone en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'SALIDA_BASCULA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar el inicio de desenlone en este momento, aún no se registra la salida de báscula.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        //Llega aqui porque la ultima asistencia si es una SALIDA_ BASCULA
                                        //Por ende registrar su inicio de desenlone
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'INICIO_DESENLONE',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Inicio de desenlone registrado correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de desenlone en este momento, aún no se registra la salida de báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de desenlone no registrado, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de desenlone no registrado, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_DESENLONE":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESENLONE')
                            ->first();

                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la salida de desenlone anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasInicioMuestreo = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_MUESTREO')->first();
                            $hasSalidaMuestreo = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_MUESTREO')->first();
                            $hasInicioDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_DESCARGA')->first();
                            $hasSalidaDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_DESCARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasInicioMuestreo || $hasSalidaMuestreo || $hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una salida de desenlone en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'INICIO_DESENLONE') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la salida de desenlone en este momento, aún no se registra el inicio de desenlone.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        //Llega aqui porque la ultima asistencia si es una INICIO_DESENLONE
                                        //Por ende registrar su salida de desenlone
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_DESENLONE',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Salida de desenlone registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la salida de desenlone en este momento, aún no se registra el inicio de desenlone.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de desenlone no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de desenlone no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "INICIO_MUESTREO":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_MUESTREO')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado el inicio de muestreo anteriormente, no puede volver a registrarlo nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalidaMuestreo = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_MUESTREO')->first();
                            $hasInicioDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_DESCARGA')->first();
                            $hasSalidaDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_DESCARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaMuestreo || $hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaMuestreo) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de muestreo debido a que ya se registro una salida de muestreo.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar el inicio de muestreo en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();

                                if ($last_att) {
                                    if ($last_att['att_type'] != 'SALIDA_DESENLONE') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar el inicio de muestreo en este momento, aún no se registra la salida del desenlone.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'INICIO_MUESTREO',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Inicio de muestreo registrado correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de muestreo en este momento, aún no se registra la salida de desenlone.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        //Si esta vetado, no registrar su entrada y devolver el mensaje
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de muestreo no registrado, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de muestreo no registrado, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_MUESTREO":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_MUESTREO')
                            ->first();

                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la salida de muestreo anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasInicioDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_DESCARGA')->first();
                            $hasSalidaDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_DESCARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una salida de muestreo en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();

                                if ($last_att != null) {
                                    if ($last_att['att_type'] != 'INICIO_MUESTREO') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la salida de muestreo en este momento, aún no se registra el inicio de muestreo.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_MUESTREO',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Salida de muestreo registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la salida de muestreo en este momento, aún no se registra el inicio de muestreo.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de muestreo no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de muestreo no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "INICIO_DESCARGA":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_DESCARGA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado el inicio de descarga anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalidaDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_DESCARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaDescarga) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de descarga debido a que ya se registro una salida de descarga.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar el inicio de descarga en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();

                                if ($last_att != null) {
                                    if ($last_att['att_type'] != 'SALIDA_MUESTREO') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar el inicio de descarga en este momento, aún no se registra la salida de muestreo.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'INICIO_DESCARGA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Inicio de descarga registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de descarga en este momento, aún no se registra la salida de muestreo.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de descarga no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de descarga no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_DESCARGA":
                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESCARGA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la salida de descarga anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una salida de descarga en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'INICIO_DESCARGA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la salida de descarga en este momento, aún no se registra el inicio de descarga.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_DESCARGA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Salida de descarga registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la salida de descarga en este momento, aún no se registra el inicio de descarga.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de descarga no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de descarga no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "ENTRADA_BASCULA_2":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')
                            ->first();

                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la segunda entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaBascula2) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda entrada a báscula debido a que ya se registro una segunda salida de báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar la segunda entrada a báscula en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'SALIDA_DESCARGA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de descarga.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'ENTRADA_BASCULA_2',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Segunda entrada a báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de descarga.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else  if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda entrada a báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda entrada a báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_BASCULA_2":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la segunda salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();
                            if ($hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una segunda salida de báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'ENTRADA_BASCULA_2') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_BASCULA_2',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Segunda salida a báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda salida de báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda salida de báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA')->first();

                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado su salida anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $last_att = Route::findOrFail($idRuta)
                            ->attendances()
                            ->orderBy('date_time', 'desc')
                            ->limit(1)
                            ->first();

                        if ($last_att != null) {

                            $attendace = new Attendance([
                                'date_time' => date('Y-m-d H:i:s'),
                                'att_type' => 'SALIDA',
                                'user_who_register' => $request->user_who_register
                            ]);

                            $ruta = Route::findOrFail($idRuta);
                            if ($ruta->attendances()->save($attendace)) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Salida registrada correctamente.',
                                    'success' => true
                                ])->response()->setStatusCode(200);
                            } else {
                                return $this->errorResponse('Error al registrar la asistencia.', 500);
                            }
                        } else {
                            //Llega aqui por que no hay ninguna asistencia registrada
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'No se puede registrar la salida en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        }
                    }
                    break;
            }
        } else {
            return (new AttChoferResource($chofer))->additional([
                'msg' => 'Ha escaneado un QR de un chofer diferente al que se selecciono.',
                'success' => false
            ])->response()->setStatusCode(200);
        }
    }

    //Metodo para registrar el movimiento en caso de que el chofer este como incompleto
    public function registrarMovimientoCebadaIncompleto(Request $request, $id)
    {
        $request->validate([
            'idChofer' => 'required',
            'user_who_register' => 'required',
            'movimiento' => 'required'
        ]);

        $idRuta = $id;
        $movimiento = $request->movimiento;
        $user_who_register = $request->user_who_register;

        //Obtener la información del chofer ligado a esa ruta o recorrido
        $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
            ->join('route', 'user.idUser', '=', 'route.FK_idUser')
            ->where('route.idRoute', $idRuta)
            ->firstOrFail();

        //Comprobar si el id del usuario coincide con id del valor del codigo QR
        if ($chofer['idUser'] == $request->idChofer) {

            //Ejecutar un switch case para verificar que movimiento de cebada de tiene que registrar
            switch ($movimiento) {
                case "ENTRADA_BASCULA":

                    //Verificar si ya se registro este movimiento
                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'ENTRADA_BASCULA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado una entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        //Verificar si ya se registro un movimiento posterior a este movimiento
                        //Si ya se registro un movimiento posterior, no registrar este movimiento
                        $hasSalidaBascula = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA')->first();
                        $hasInicioDesenlone = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_DESENLONE')->first();
                        $hasSalidaDesenlone = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESENLONE')->first();
                        $hasInicioMuestreo = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_MUESTREO')->first();
                        $hasSalidaMuestreo = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_MUESTREO')->first();
                        $hasInicioDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_DESCARGA')->first();
                        $hasSalidaDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESCARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaBascula || $hasInicioDesenlone || $hasSalidaDesenlone || $hasInicioMuestreo || $hasSalidaMuestreo || $hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaBascula) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la entrada de báscula debido a que ya se registro una salida de báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una entrada a báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            //Si no hay un movimiento posterior, verificar ahora cual fue el ultimo movimiento registrado
                            //Esto es debido a que los movimientos deben seguir un orden
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                //Verificar que su ultimo movimiento sea una ENTRADA
                                if ($last_att['att_type'] != 'ENTRADA') {
                                    // Si no es una ENTRADA es probable que el usuario se haya equivocado al elegir el movimiento
                                    // O bien, el usuario haya olvidado registrar el movimiento ENTRADA
                                    // Devolver una respuesta con una variable adicional llamada olvido => true, para
                                    // indicar en el frontend una advertencia y mostrar un boton preguntando si desea registrar el movimiento de todas formas

                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    //Si es una ENTRADA, proceder a registrar este movimiento
                                    $attendance = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'ENTRADA_BASCULA',
                                        'user_who_register' => $user_who_register
                                    ]);
                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendance)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Entrada a báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {

                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_BASCULA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_BASCULA')
                        ->first();

                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado una salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasInicioDesenlone = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_DESENLONE')->first();
                        $hasSalidaDesenlone = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESENLONE')->first();
                        $hasInicioMuestreo = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_MUESTREO')->first();
                        $hasSalidaMuestreo = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_MUESTREO')->first();
                        $hasInicioDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_DESCARGA')->first();
                        $hasSalidaDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESCARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasInicioDesenlone || $hasSalidaDesenlone || $hasInicioMuestreo || $hasSalidaMuestreo || $hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar una salida de báscula en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'ENTRADA_BASCULA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a la báscula.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    //Llega aqui porque la ultima asistencia si es una ENTRADA_BASCULA
                                    //Por ende registrar su entrada a salida de bascula
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_BASCULA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Salida de báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "INICIO_DESENLONE":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'INICIO_DESENLONE')
                        ->first();

                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado el inicio a desenlone anteriormente, no puede volver a registrarlo nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalidaDesenlone = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESENLONE')->first();
                        $hasInicioMuestreo = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_MUESTREO')->first();
                        $hasSalidaMuestreo = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_MUESTREO')->first();
                        $hasInicioDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_DESCARGA')->first();
                        $hasSalidaDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESCARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaDesenlone || $hasInicioMuestreo || $hasSalidaMuestreo || $hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaDesenlone) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar el inicio de desenlone debido a que ya se registro una salida de desenlone.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar el inicio de desenlone en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'SALIDA_BASCULA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de desenlone en este momento, aún no se registra la salida de báscula.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    //Llega aqui porque la ultima asistencia si es una SALIDA_ BASCULA
                                    //Por ende registrar su inicio de desenlone
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'INICIO_DESENLONE',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Inicio de desenlone registrado correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar el inicio de desenlone en este momento, aún no se registra la salida de báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_DESENLONE":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_DESENLONE')
                        ->first();

                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la salida de desenlone anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasInicioMuestreo = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_MUESTREO')->first();
                        $hasSalidaMuestreo = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_MUESTREO')->first();
                        $hasInicioDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_DESCARGA')->first();
                        $hasSalidaDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESCARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasInicioMuestreo || $hasSalidaMuestreo || $hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar una salida de desenlone en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'INICIO_DESENLONE') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la salida de desenlone en este momento, aún no se registra el inicio de desenlone.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    //Llega aqui porque la ultima asistencia si es una INICIO_DESENLONE
                                    //Por ende registrar su salida de desenlone
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_DESENLONE',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Salida de desenlone registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la salida de desenlone en este momento, aún no se registra el inicio de desenlone.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "INICIO_MUESTREO":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'INICIO_MUESTREO')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado el inicio de muestreo anteriormente, no puede volver a registrarlo nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalidaMuestreo = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_MUESTREO')->first();
                        $hasInicioDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_DESCARGA')->first();
                        $hasSalidaDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESCARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaMuestreo || $hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaMuestreo) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar el inicio de muestreo debido a que ya se registro una salida de muestreo.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar el inicio de muestreo en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();

                            if ($last_att) {
                                if ($last_att['att_type'] != 'SALIDA_DESENLONE') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de muestreo en este momento, aún no se registra la salida del desenlone.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'INICIO_MUESTREO',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Inicio de muestreo registrado correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar el inicio de muestreo en este momento, aún no se registra la salida de desenlone.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_MUESTREO":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_MUESTREO')
                        ->first();

                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la salida de muestreo anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasInicioDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_DESCARGA')->first();
                        $hasSalidaDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESCARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasInicioDescarga || $hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar una salida de muestreo en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();

                            if ($last_att != null) {
                                if ($last_att['att_type'] != 'INICIO_MUESTREO') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la salida de muestreo en este momento, aún no se registra el inicio de muestreo.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_MUESTREO',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Salida de muestreo registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la salida de muestreo en este momento, aún no se registra el inicio de muestreo.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "INICIO_DESCARGA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'INICIO_DESCARGA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado el inicio de descarga anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalidaDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_DESCARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaDescarga) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar el inicio de descarga debido a que ya se registro una salida de descarga.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar el inicio de descarga en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();

                            if ($last_att != null) {
                                if ($last_att['att_type'] != 'SALIDA_MUESTREO') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de descarga en este momento, aún no se registra la salida de muestreo.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'INICIO_DESCARGA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Inicio de descarga registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar el inicio de descarga en este momento, aún no se registra la salida de muestreo.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_DESCARGA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_DESCARGA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la salida de descarga anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar una salida de descarga en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'INICIO_DESCARGA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la salida de descarga en este momento, aún no se registra el inicio de descarga.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_DESCARGA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Salida de descarga registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la salida de descarga en este momento, aún no se registra el inicio de descarga.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "ENTRADA_BASCULA_2":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'ENTRADA_BASCULA_2')
                        ->first();

                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la segunda entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaBascula2) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la segunda entrada a báscula debido a que ya se registro una segunda salida de báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar la segunda entrada a báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'SALIDA_DESCARGA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de descarga.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'ENTRADA_BASCULA_2',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Segunda entrada a báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de descarga.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_BASCULA_2":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_BASCULA_2')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la segunda salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();
                        if ($hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar una segunda salida de báscula en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'ENTRADA_BASCULA_2') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_BASCULA_2',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Segunda salida a báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;
            }
        } else {
            return (new AttChoferResource($chofer))->additional([
                'msg' => 'Ha escaneado un QR de un chofer diferente al que se selecciono.',
                'success' => false
            ])->response()->setStatusCode(200);
        }
    }

    //Metodo para registar un movimiento en el caso posible de que algun movimiento anterior no fue registrado o ha sido olvidado de registrar
    public function registrarMovimientoCebadaForzar(Request $request, $id)
    {
        $request->validate([
            'idChofer' => 'required',
            'user_who_register' => 'required',
            'movimiento' => 'required'
        ]);

        $idRuta = $id;
        $movimiento = $request->movimiento;
        $user_who_register = $request->user_who_register;

        //Obtener la información del chofer ligado a esa ruta o recorrido
        $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
            ->join('route', 'user.idUser', '=', 'route.FK_idUser')
            ->where('route.idRoute', $idRuta)
            ->firstOrFail();

        //Comprobar si el id del usuario coincide con id del valor del codigo QR
        if ($chofer['idUser'] == $request->idChofer) {

            //Ejecutar un switch case para verificar que movimiento de cebada de tiene que registrar
            switch ($movimiento) {
                case "ENTRADA_BASCULA":

                    $attendance = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'ENTRADA_BASCULA',
                        'user_who_register' => $user_who_register
                    ]);
                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendance)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Entrada a báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_BASCULA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_BASCULA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "INICIO_DESENLONE":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'INICIO_DESENLONE',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de desenlone registrado correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_DESENLONE":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_DESENLONE',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de desenlone registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "INICIO_MUESTREO":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'INICIO_MUESTREO',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de muestreo registrado correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_MUESTREO":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_MUESTREO',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de muestreo registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "INICIO_DESCARGA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'INICIO_DESCARGA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de descarga registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_DESCARGA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_DESCARGA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de descarga registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "ENTRADA_BASCULA_2":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'ENTRADA_BASCULA_2',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda entrada a báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_BASCULA_2":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_BASCULA_2',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda salida a báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;
            }
        } else {
            return (new AttChoferResource($chofer))->additional([
                'msg' => 'Ha escaneado un QR de un chofer diferente al que se selecciono.',
                'success' => false
            ])->response()->setStatusCode(200);
        }
    }



    // -------------------------------- CARGA DE MALTA -------------------------------------

    //Metodo para registrar un movimiento de carga de malta por primera vez
    public function registrarMovimientoMalta(Request $request, $id)
    {
        $request->validate([
            'idChofer' => 'required',
            'user_who_register' => 'required',
            'movimiento' => 'required'
        ]);

        $idRuta = $id;
        $movimiento = $request->movimiento;
        $user_who_register = $request->user_who_register;

        //Obtener la información del chofer ligado a esa ruta o recorrido
        $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
            ->join('route', 'user.idUser', '=', 'route.FK_idUser')
            ->where('route.idRoute', $idRuta)
            ->firstOrFail();

        //Comprobar si el id del usuario coincide con id del valor del codigo QR
        if ($chofer['idUser'] == $request->idChofer) {
            //Verificar que movimiento se tiene que registrar
            switch ($movimiento) {
                case "ENTRADA_BASCULA":

                    //Consultar el estatus del chofer
                    if ($chofer['estatus'] === 'activo') {
                        //Verificar si ya se registro este movimiento
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado una entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalidaBascula = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA')->first();
                            $hasInicioCarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_CARGA')->first();
                            $hasSalidaCarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_CARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaBascula || $hasInicioCarga || $hasSalidaCarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaBascula) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la entrada de báscula debido a que ya se registro una salida de báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar una entrada a báscula en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                //Si no hay un movimiento posterior, verificar ahora cual fue el ultimo movimiento registrado
                                //Esto es debido a que los movimientos deben seguir un orden
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'ENTRADA') {
                                        // Si no es una ENTRADA es probable que el usuario se haya equivocado al elegir el movimiento
                                        // O bien, el usuario haya olvidado registrar el movimiento ENTRADA
                                        // Devolver una respuesta con una variable adicional llamada olvido => true, para
                                        // indicar en el frontend una advertencia y mostrar un boton preguntando si desea registrar el movimiento de todas formas
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        //Llega aqui porque la ultima asistencia si es una ENTRADA
                                        //Por ende registrar su entrada a bascula
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'ENTRADA_BASCULA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Entrada a báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Entrada a báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        //Si el chofer esta como incompleto, es debido a que no hay subido su documentacion completa, video o quiz
                        //Devolver en la respuesta una variable permitir => true al frontend
                        //De este modo, se mostrar un boton mostrando una advertencia dando a elegir al usuario si se registra el movimiento o no
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Entrada a báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_BASCULA":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado una salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasInicioCarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_CARGA')->first();
                            $hasSalidaCarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_CARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasInicioCarga || $hasSalidaCarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una salida de báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'ENTRADA_BASCULA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a la báscula.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_BASCULA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Salida de báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "INICIO_CARGA":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_CARGA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado el inicio de carga de malta anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalidaDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_CARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaDescarga) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de carga de malta debido a que ya se registro una salida de carga de malta.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar el inicio de carga de malta en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'SALIDA_BASCULA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar el inicio de carga de malta en este momento, aún no se registra la salida de báscula.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'INICIO_CARGA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Inicio de carga de malta registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de carga de malta en este momento, aún no se registra la salida de báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de carga de malta no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de carga de malta no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_CARGA":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_CARGA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la salida de carga de malta anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar la salida de carga de malta en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'INICIO_CARGA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la salida de carga de malta en este momento, aún no se registra el inicio de carga de malta.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_CARGA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Salida de carga de malta registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la salida de carga de malta en este momento, aún no se registra el inicio de carga de malta.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de carga de malta no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de carga de malta no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "ENTRADA_BASCULA_2":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la segunda entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaBascula2) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda entrada a báscula debido a que ya se registro una segunda salida de báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar la segunda entrada a báscula en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'SALIDA_CARGA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de carga de malta.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'ENTRADA_BASCULA_2',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Segunda entrada a báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de carga de malta.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda entrada a báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda entrada a báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_BASCULA_2":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la segunda salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();
                            if ($hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una segunda salida de báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'ENTRADA_BASCULA_2') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_BASCULA_2',
                                            'user_who_register' => $request->user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Segunda salida a báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda salida de báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda salida de báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA')->first();

                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado su salida anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $last_att = Route::findOrFail($idRuta)
                            ->attendances()
                            ->orderBy('date_time', 'desc')
                            ->limit(1)
                            ->first();

                        if ($last_att != null) {

                            $attendace = new Attendance([
                                'date_time' => date('Y-m-d H:i:s'),
                                'att_type' => 'SALIDA',
                                'user_who_register' => $request->user_who_register
                            ]);

                            $ruta = Route::findOrFail($idRuta);
                            if ($ruta->attendances()->save($attendace)) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Salida registrada correctamente.',
                                    'success' => true
                                ])->response()->setStatusCode(200);
                            } else {
                                return $this->errorResponse('Error al registrar la asistencia.', 500);
                            }
                        } else {
                            //Llega aqui por que no hay ninguna asistencia registrada
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'No se puede registrar la salida en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        }
                    }

                    break;
            }
        } else {
            return (new AttChoferResource($chofer))->additional([
                'msg' => 'Ha escaneado un QR de un chofer diferente al que se selecciono.',
                'success' => false
            ])->response()->setStatusCode(200);
        }
    }

    //Metodo para registra un movimiento de carga de malta si el chofer esta como incompleto
    public function registrarMovimientoMaltaIncompleto(Request $request, $id)
    {
        $request->validate([
            'idChofer' => 'required',
            'user_who_register' => 'required',
            'movimiento' => 'required'
        ]);

        $idRuta = $id;
        $movimiento = $request->movimiento;
        $user_who_register = $request->user_who_register;

        //Obtener la información del chofer ligado a esa ruta o recorrido
        $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
            ->join('route', 'user.idUser', '=', 'route.FK_idUser')
            ->where('route.idRoute', $idRuta)
            ->firstOrFail();

        //Comprobar si el id del usuario coincide con id del valor del codigo QR
        if ($chofer['idUser'] == $request->idChofer) {
            switch ($movimiento) {
                case "ENTRADA_BASCULA":

                    //Verificar si ya se registro este movimiento
                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'ENTRADA_BASCULA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado una entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalidaBascula = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA')->first();
                        $hasInicioCarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_CARGA')->first();
                        $hasSalidaCarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_CARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaBascula || $hasInicioCarga || $hasSalidaCarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaBascula) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la entrada de báscula debido a que ya se registro una salida de báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una entrada a báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            //Si no hay un movimiento posterior, verificar ahora cual fue el ultimo movimiento registrado
                            //Esto es debido a que los movimientos deben seguir un orden
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'ENTRADA') {
                                    // Si no es una ENTRADA es probable que el usuario se haya equivocado al elegir el movimiento
                                    // O bien, el usuario haya olvidado registrar el movimiento ENTRADA
                                    // Devolver una respuesta con una variable adicional llamada olvido => true, para
                                    // indicar en el frontend una advertencia y mostrar un boton preguntando si desea registrar el movimiento de todas formas
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    //Llega aqui porque la ultima asistencia si es una ENTRADA
                                    //Por ende registrar su entrada a bascula
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'ENTRADA_BASCULA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Entrada a báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_BASCULA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_BASCULA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado una salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasInicioCarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_CARGA')->first();
                        $hasSalidaCarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_CARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasInicioCarga || $hasSalidaCarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar una salida de báscula en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'ENTRADA_BASCULA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a la báscula.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_BASCULA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Salida de báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "INICIO_CARGA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'INICIO_CARGA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado el inicio de carga de malta anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalidaDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_CARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaDescarga) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar el inicio de carga de malta debido a que ya se registro una salida de carga de malta.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar el inicio de carga de malta en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'SALIDA_BASCULA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de carga de malta en este momento, aún no se registra la salida de báscula.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'INICIO_CARGA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Inicio de carga de malta registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar el inicio de carga de malta en este momento, aún no se registra la salida de báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_CARGA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_CARGA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la salida de carga de malta anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar la salida de carga de malta en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'INICIO_CARGA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la salida de carga de malta en este momento, aún no se registra el inicio de carga de malta.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_CARGA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Salida de carga de malta registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la salida de carga de malta en este momento, aún no se registra el inicio de carga de malta.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "ENTRADA_BASCULA_2":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'ENTRADA_BASCULA_2')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la segunda entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaBascula2) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la segunda entrada a báscula debido a que ya se registro una segunda salida de báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar la segunda entrada a báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'SALIDA_CARGA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de carga de malta.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'ENTRADA_BASCULA_2',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Segunda entrada a báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de carga de malta.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_BASCULA_2":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_BASCULA_2')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la segunda salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();
                        if ($hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar una segunda salida de báscula en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'ENTRADA_BASCULA_2') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_BASCULA_2',
                                        'user_who_register' => $request->user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Segunda salida a báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA')->first();

                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado su salida anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $last_att = Route::findOrFail($idRuta)
                            ->attendances()
                            ->orderBy('date_time', 'desc')
                            ->limit(1)
                            ->first();

                        if ($last_att != null) {

                            $attendace = new Attendance([
                                'date_time' => date('Y-m-d H:i:s'),
                                'att_type' => 'SALIDA',
                                'user_who_register' => $request->user_who_register
                            ]);

                            $ruta = Route::findOrFail($idRuta);
                            if ($ruta->attendances()->save($attendace)) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Salida registrada correctamente.',
                                    'success' => true
                                ])->response()->setStatusCode(200);
                            } else {
                                return $this->errorResponse('Error al registrar la asistencia.', 500);
                            }
                        } else {
                            //Llega aqui por que no hay ninguna asistencia registrada
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'No se puede registrar la salida en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        }
                    }

                    break;
            }
        } else {
            return (new AttChoferResource($chofer))->additional([
                'msg' => 'Ha escaneado un QR de un chofer diferente al que se selecciono.',
                'success' => false
            ])->response()->setStatusCode(200);
        }
    }

    //Metodo para registrar un movimiento de carga de malta en caso de que se haya olvidado registrar un movimiento anterior o se haya olvidado registrar
    public function registrarMovimientoMaltaForzar(Request $request, $id){
        $request->validate([
            'idChofer' => 'required',
            'user_who_register' => 'required',
            'movimiento' => 'required'
        ]);

        $idRuta = $id;
        $movimiento = $request->movimiento;
        $user_who_register = $request->user_who_register;

        //Obtener la información del chofer ligado a esa ruta o recorrido
        $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
            ->join('route', 'user.idUser', '=', 'route.FK_idUser')
            ->where('route.idRoute', $idRuta)
            ->firstOrFail();

        //Comprobar si el id del usuario coincide con id del valor del codigo QR
        if ($chofer['idUser'] == $request->idChofer) {
            switch($movimiento) {
                case "ENTRADA_BASCULA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'ENTRADA_BASCULA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Entrada a báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_BASCULA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_BASCULA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "INICIO_CARGA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'INICIO_CARGA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de carga de malta registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_CARGA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_CARGA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de carga de malta registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "ENTRADA_BASCULA_2":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'ENTRADA_BASCULA_2',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda entrada a báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_BASCULA_2":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_BASCULA_2',
                        'user_who_register' => $request->user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda salida a báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA',
                        'user_who_register' => $request->user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;
            }
        } else {
            return (new AttChoferResource($chofer))->additional([
                'msg' => 'Ha escaneado un QR de un chofer diferente al que se selecciono.',
                'success' => false
            ])->response()->setStatusCode(200);
        }
    }



    // -------------------------------- CARGA DE COPRODUCTO ----------------------------------
    //Metodo para registrar un movimiento de carga de malta por primera vez
    public function registrarMovimientoCoproducto(Request $request, $id)
    {
        $request->validate([
            'idChofer' => 'required',
            'user_who_register' => 'required',
            'movimiento' => 'required'
        ]);

        $idRuta = $id;
        $movimiento = $request->movimiento;
        $user_who_register = $request->user_who_register;

        //Obtener la información del chofer ligado a esa ruta o recorrido
        $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
            ->join('route', 'user.idUser', '=', 'route.FK_idUser')
            ->where('route.idRoute', $idRuta)
            ->firstOrFail();

        //Comprobar si el id del usuario coincide con id del valor del codigo QR
        if ($chofer['idUser'] == $request->idChofer) {
            //Verificar que movimiento se tiene que registrar
            switch ($movimiento) {
                case "ENTRADA_BASCULA":

                    //Consultar el estatus del chofer
                    if ($chofer['estatus'] === 'activo') {
                        //Verificar si ya se registro este movimiento
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado una entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalidaBascula = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA')->first();
                            $hasInicioCarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_CARGA')->first();
                            $hasSalidaCarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_CARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaBascula || $hasInicioCarga || $hasSalidaCarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaBascula) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la entrada de báscula debido a que ya se registro una salida de báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar una entrada a báscula en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                //Si no hay un movimiento posterior, verificar ahora cual fue el ultimo movimiento registrado
                                //Esto es debido a que los movimientos deben seguir un orden
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'ENTRADA') {
                                        // Si no es una ENTRADA es probable que el usuario se haya equivocado al elegir el movimiento
                                        // O bien, el usuario haya olvidado registrar el movimiento ENTRADA
                                        // Devolver una respuesta con una variable adicional llamada olvido => true, para
                                        // indicar en el frontend una advertencia y mostrar un boton preguntando si desea registrar el movimiento de todas formas
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        //Llega aqui porque la ultima asistencia si es una ENTRADA
                                        //Por ende registrar su entrada a bascula
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'ENTRADA_BASCULA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Entrada a báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Entrada a báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        //Si el chofer esta como incompleto, es debido a que no hay subido su documentacion completa, video o quiz
                        //Devolver en la respuesta una variable permitir => true al frontend
                        //De este modo, se mostrar un boton mostrando una advertencia dando a elegir al usuario si se registra el movimiento o no
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Entrada a báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_BASCULA":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado una salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasInicioCarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'INICIO_CARGA')->first();
                            $hasSalidaCarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_CARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasInicioCarga || $hasSalidaCarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una salida de báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'ENTRADA_BASCULA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a la báscula.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_BASCULA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Salida de báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "INICIO_CARGA":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_CARGA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado el inicio de carga de coproducto anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalidaDescarga = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_CARGA')->first();
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaDescarga) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de carga de coproducto debido a que ya se registro una salida de carga de malta.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar el inicio de carga de coproducto en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'SALIDA_BASCULA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar el inicio de carga de coproducto en este momento, aún no se registra la salida de báscula.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'INICIO_CARGA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Inicio de carga de coproducto registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de carga de coproducto en este momento, aún no se registra la salida de báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de carga de coproducto no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de carga de coproducto no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_CARGA":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_CARGA')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la salida de carga de coproducto anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasEntradaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar la salida de carga de coproducto en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'INICIO_CARGA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la salida de carga de coproducto en este momento, aún no se registra el inicio de carga de coproducto.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_CARGA',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Salida de carga de coproducto registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la salida de carga de coproducto en este momento, aún no se registra el inicio de carga de coproducto.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de carga de coproducto no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de carga de coproducto no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "ENTRADA_BASCULA_2":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la segunda entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalidaBascula2 = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA_BASCULA_2')->first();
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();

                            if ($hasSalidaBascula2 || $hasSalida) {
                                if ($hasSalidaBascula2) {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda entrada a báscula debido a que ya se registro una segunda salida de báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'Ya no es posible registrar la segunda entrada a báscula en este momento.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'SALIDA_CARGA') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de carga de malta.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'ENTRADA_BASCULA_2',
                                            'user_who_register' => $user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Segunda entrada a báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de carga de coproducto.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda entrada a báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda entrada a báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA_BASCULA_2":

                    if ($chofer['estatus'] === 'activo') {
                        $has = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')
                            ->first();
                        if ($has) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya ha registrado la segunda salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $hasSalida = Route::findOrFail($idRuta)
                                ->attendances()
                                ->where('att_type', 'SALIDA')->first();
                            if ($hasSalida) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una segunda salida de báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                $last_att = Route::findOrFail($idRuta)
                                    ->attendances()
                                    ->orderBy('date_time', 'desc')
                                    ->limit(1)
                                    ->first();
                                if ($last_att) {
                                    if ($last_att['att_type'] != 'ENTRADA_BASCULA_2') {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                            'success' => false,
                                            'olvido' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        $attendace = new Attendance([
                                            'date_time' => date('Y-m-d H:i:s'),
                                            'att_type' => 'SALIDA_BASCULA_2',
                                            'user_who_register' => $request->user_who_register
                                        ]);

                                        $ruta = Route::findOrFail($idRuta);
                                        if ($ruta->attendances()->save($attendace)) {
                                            return (new AttChoferResource($chofer))->additional([
                                                'msg' => 'Segunda salida a báscula registrada correctamente.',
                                                'success' => true
                                            ])->response()->setStatusCode(200);
                                        } else {
                                            return $this->errorResponse('Error al registrar la asistencia.', 500);
                                        }
                                    }
                                } else {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                        'success' => false
                                    ])->response()->setStatusCode(200);
                                }
                            }
                        }
                    } else if ($chofer['estatus'] === 'vetado') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda salida de báscula no registrada, el chofer esta vetado de la planta.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else if ($chofer['estatus'] === 'incompleto') {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda salida de báscula no registrada, el chófer aún no sube documentación, video o contesta el cuestionario de inducción de seguridad.',
                            'success' => false,
                            'permitir' => true
                        ])->response()->setStatusCode(200);
                    }

                    break;

                case "SALIDA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA')->first();

                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado su salida anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $last_att = Route::findOrFail($idRuta)
                            ->attendances()
                            ->orderBy('date_time', 'desc')
                            ->limit(1)
                            ->first();

                        if ($last_att != null) {

                            $attendace = new Attendance([
                                'date_time' => date('Y-m-d H:i:s'),
                                'att_type' => 'SALIDA',
                                'user_who_register' => $request->user_who_register
                            ]);

                            $ruta = Route::findOrFail($idRuta);
                            if ($ruta->attendances()->save($attendace)) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Salida registrada correctamente.',
                                    'success' => true
                                ])->response()->setStatusCode(200);
                            } else {
                                return $this->errorResponse('Error al registrar la asistencia.', 500);
                            }
                        } else {
                            //Llega aqui por que no hay ninguna asistencia registrada
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'No se puede registrar la salida en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        }
                    }

                    break;
            }
        } else {
            return (new AttChoferResource($chofer))->additional([
                'msg' => 'Ha escaneado un QR de un chofer diferente al que se selecciono.',
                'success' => false
            ])->response()->setStatusCode(200);
        }
    }

    //Metodo para registra un movimiento de carga de malta si el chofer esta como incompleto
    public function registrarMovimientoCoproductoIncompleto(Request $request, $id)
    {
        $request->validate([
            'idChofer' => 'required',
            'user_who_register' => 'required',
            'movimiento' => 'required'
        ]);

        $idRuta = $id;
        $movimiento = $request->movimiento;
        $user_who_register = $request->user_who_register;

        //Obtener la información del chofer ligado a esa ruta o recorrido
        $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
            ->join('route', 'user.idUser', '=', 'route.FK_idUser')
            ->where('route.idRoute', $idRuta)
            ->firstOrFail();

        //Comprobar si el id del usuario coincide con id del valor del codigo QR
        if ($chofer['idUser'] == $request->idChofer) {
            switch ($movimiento) {
                case "ENTRADA_BASCULA":

                    //Verificar si ya se registro este movimiento
                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'ENTRADA_BASCULA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado una entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalidaBascula = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA')->first();
                        $hasInicioCarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_CARGA')->first();
                        $hasSalidaCarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_CARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaBascula || $hasInicioCarga || $hasSalidaCarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaBascula) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la entrada de báscula debido a que ya se registro una salida de báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar una entrada a báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            //Si no hay un movimiento posterior, verificar ahora cual fue el ultimo movimiento registrado
                            //Esto es debido a que los movimientos deben seguir un orden
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'ENTRADA') {
                                    // Si no es una ENTRADA es probable que el usuario se haya equivocado al elegir el movimiento
                                    // O bien, el usuario haya olvidado registrar el movimiento ENTRADA
                                    // Devolver una respuesta con una variable adicional llamada olvido => true, para
                                    // indicar en el frontend una advertencia y mostrar un boton preguntando si desea registrar el movimiento de todas formas
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    //Llega aqui porque la ultima asistencia si es una ENTRADA
                                    //Por ende registrar su entrada a bascula
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'ENTRADA_BASCULA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Entrada a báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar una entrada a báscula en este momento, aún no se registra la entrada a la planta.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_BASCULA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_BASCULA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado una salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasInicioCarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'INICIO_CARGA')->first();
                        $hasSalidaCarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_CARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasInicioCarga || $hasSalidaCarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar una salida de báscula en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'ENTRADA_BASCULA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a la báscula.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_BASCULA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Salida de báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar una salida a báscula en este momento, aún no se registra la entrada a báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "INICIO_CARGA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'INICIO_CARGA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado el inicio de carga de coproducto anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalidaDescarga = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_CARGA')->first();
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaDescarga || $hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaDescarga) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar el inicio de carga de coproducto debido a que ya se registro una salida de carga de coproducto.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar el inicio de carga de coproducto en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'SALIDA_BASCULA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar el inicio de carga de coproducto en este momento, aún no se registra la salida de báscula.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'INICIO_CARGA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Inicio de carga de coproducto registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar el inicio de carga de coproducto en este momento, aún no se registra la salida de báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_CARGA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_CARGA')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la salida de carga de coproducto anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasEntradaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'ENTRADA_BASCULA_2')->first();
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasEntradaBascula2 || $hasSalidaBascula2 || $hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar la salida de carga de coproducto en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'INICIO_CARGA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la salida de carga de coproducto en este momento, aún no se registra el inicio de carga de coproducto.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_CARGA',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Salida de carga de coproducto registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la salida de carga de coproducto en este momento, aún no se registra el inicio de carga de coproducto.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "ENTRADA_BASCULA_2":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'ENTRADA_BASCULA_2')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la segunda entrada a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalidaBascula2 = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA_BASCULA_2')->first();
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();

                        if ($hasSalidaBascula2 || $hasSalida) {
                            if ($hasSalidaBascula2) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la segunda entrada a báscula debido a que ya se registro una segunda salida de báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Ya no es posible registrar la segunda entrada a báscula en este momento.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'SALIDA_CARGA') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de carga de coproducto.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'ENTRADA_BASCULA_2',
                                        'user_who_register' => $user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Segunda entrada a báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la segunda entrada a báscula en este momento, aún no se registra la salida de carga de coproducto.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA_BASCULA_2":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA_BASCULA_2')
                        ->first();
                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado la segunda salida a báscula anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $hasSalida = Route::findOrFail($idRuta)
                            ->attendances()
                            ->where('att_type', 'SALIDA')->first();
                        if ($hasSalida) {
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'Ya no es posible registrar una segunda salida de báscula en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        } else {
                            $last_att = Route::findOrFail($idRuta)
                                ->attendances()
                                ->orderBy('date_time', 'desc')
                                ->limit(1)
                                ->first();
                            if ($last_att) {
                                if ($last_att['att_type'] != 'ENTRADA_BASCULA_2') {
                                    return (new AttChoferResource($chofer))->additional([
                                        'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                        'success' => false,
                                        'olvido' => true
                                    ])->response()->setStatusCode(200);
                                } else {
                                    $attendace = new Attendance([
                                        'date_time' => date('Y-m-d H:i:s'),
                                        'att_type' => 'SALIDA_BASCULA_2',
                                        'user_who_register' => $request->user_who_register
                                    ]);

                                    $ruta = Route::findOrFail($idRuta);
                                    if ($ruta->attendances()->save($attendace)) {
                                        return (new AttChoferResource($chofer))->additional([
                                            'msg' => 'Segunda salida a báscula registrada correctamente.',
                                            'success' => true
                                        ])->response()->setStatusCode(200);
                                    } else {
                                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                                    }
                                }
                            } else {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'No puede registrar la segunda salida a báscula en este momento, aún no se registra la segunda entrada a báscula.',
                                    'success' => false
                                ])->response()->setStatusCode(200);
                            }
                        }
                    }

                    break;

                case "SALIDA":

                    $has = Route::findOrFail($idRuta)
                        ->attendances()
                        ->where('att_type', 'SALIDA')->first();

                    if ($has) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Ya ha registrado su salida anteriormente, no puede volver a registrarla nuevamente.',
                            'success' => false
                        ])->response()->setStatusCode(200);
                    } else {
                        $last_att = Route::findOrFail($idRuta)
                            ->attendances()
                            ->orderBy('date_time', 'desc')
                            ->limit(1)
                            ->first();

                        if ($last_att != null) {

                            $attendace = new Attendance([
                                'date_time' => date('Y-m-d H:i:s'),
                                'att_type' => 'SALIDA',
                                'user_who_register' => $request->user_who_register
                            ]);

                            $ruta = Route::findOrFail($idRuta);
                            if ($ruta->attendances()->save($attendace)) {
                                return (new AttChoferResource($chofer))->additional([
                                    'msg' => 'Salida registrada correctamente.',
                                    'success' => true
                                ])->response()->setStatusCode(200);
                            } else {
                                return $this->errorResponse('Error al registrar la asistencia.', 500);
                            }
                        } else {
                            //Llega aqui por que no hay ninguna asistencia registrada
                            return (new AttChoferResource($chofer))->additional([
                                'msg' => 'No se puede registrar la salida en este momento.',
                                'success' => false
                            ])->response()->setStatusCode(200);
                        }
                    }

                    break;
            }
        } else {
            return (new AttChoferResource($chofer))->additional([
                'msg' => 'Ha escaneado un QR de un chofer diferente al que se selecciono.',
                'success' => false
            ])->response()->setStatusCode(200);
        }
    }

    //Metodo para registrar un movimiento de carga de malta en caso de que se haya olvidado registrar un movimiento anterior o se haya olvidado registrar
    public function registrarMovimientoCoproductoForzar(Request $request, $id){
        $request->validate([
            'idChofer' => 'required',
            'user_who_register' => 'required',
            'movimiento' => 'required'
        ]);

        $idRuta = $id;
        $movimiento = $request->movimiento;
        $user_who_register = $request->user_who_register;

        //Obtener la información del chofer ligado a esa ruta o recorrido
        $chofer = User::select('idUser', 'nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'route.empresa as empresa', 'estatus')
            ->join('route', 'user.idUser', '=', 'route.FK_idUser')
            ->where('route.idRoute', $idRuta)
            ->firstOrFail();

        //Comprobar si el id del usuario coincide con id del valor del codigo QR
        if ($chofer['idUser'] == $request->idChofer) {
            switch($movimiento) {
                case "ENTRADA_BASCULA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'ENTRADA_BASCULA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Entrada a báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_BASCULA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_BASCULA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "INICIO_CARGA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'INICIO_CARGA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Inicio de carga de coproducto registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_CARGA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_CARGA',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida de carga de coproducto registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "ENTRADA_BASCULA_2":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'ENTRADA_BASCULA_2',
                        'user_who_register' => $user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda entrada a báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA_BASCULA_2":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA_BASCULA_2',
                        'user_who_register' => $request->user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Segunda salida a báscula registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;

                case "SALIDA":

                    $attendace = new Attendance([
                        'date_time' => date('Y-m-d H:i:s'),
                        'att_type' => 'SALIDA',
                        'user_who_register' => $request->user_who_register
                    ]);

                    $ruta = Route::findOrFail($idRuta);
                    if ($ruta->attendances()->save($attendace)) {
                        return (new AttChoferResource($chofer))->additional([
                            'msg' => 'Salida registrada correctamente.',
                            'success' => true
                        ])->response()->setStatusCode(200);
                    } else {
                        return $this->errorResponse('Error al registrar la asistencia.', 500);
                    }

                    break;
            }
        } else {
            return (new AttChoferResource($chofer))->additional([
                'msg' => 'Ha escaneado un QR de un chofer diferente al que se selecciono.',
                'success' => false
            ])->response()->setStatusCode(200);
        }
    }

}
