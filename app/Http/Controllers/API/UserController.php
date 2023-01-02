<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Controllers\ApiController;
use App\Http\Resources\BasicAdminResource;
use App\Http\Resources\BasicAllChoferesResource;
use App\Http\Resources\BasicChoferResource;
use App\Http\Resources\DetailChoferResource;
use App\Http\Resources\StatusChoferResource;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\NoChoferesResource;
use Illuminate\Support\Carbon;

class UserController extends ApiController
{
    public function __construct()
    {
        $this->middleware('jwt.verify');
    }

    //Metodo para verificar si ya se vio el video, contesto encuesta y subio documentacion
    public function requerimientos($id)
    {

        $requerimientos = User::select('verVideo', 'contestarQuiz', 'subirDocs')
            ->where('idUser', $id)
            ->firstOrFail();
        //"SELECT verVideo, contestarQuiz, subirDocs FROM user WHERE idUser = ?");
        return $this->successResponse($requerimientos);
    }

    //Metodo para actualizar que ya se vio el video por completo
    public function setVideo($id)
    {
        $user = User::findOrFail($id);
        $user->verVideo = true;
        if ($user->save()) {

            //Checar los demás requerimientos, en caso de que ya se hayan cumplido, activar al usuario
            $video = $user->verVideo;
            $quiz = $user->contestarQuiz;
            $docs = $user->subirDocs;
            if ($video == 1 && $quiz == 1 && $docs == 1) {
                $user->estatus = 'activo';
                $user->save();
            }
            return $this->successResponse(null, 'Has terminado de ver el video.');
        } else {
            return $this->errorResponse('Error al actualizar', 500);
        }
    }

    //Metodo para actualizar que ya se contesto el quiz
    public function setQuiz($id)
    {
        $user = User::findOrFail($id);
        $user->contestarQuiz = true;
        $user->lastQuizDate = Carbon::now()->format('Y-m-d');
        if ($user->save()) {

            //Checar los demás requerimientos, en caso de que ya se hayan cumplido, activar al usuario
            $video = $user->verVideo;
            $quiz = $user->contestarQuiz;
            $docs = $user->subirDocs;
            if ($video == 1 && $quiz == 1 && $docs == 1) {
                $user->estatus = 'activo';
                $user->save();
            }
            return $this->successResponse(null, 'Has terminado de contestar el cuestionario.');
        } else {
            return $this->errorResponse('Error al actualizar', 500);
        }
    }

    //Metodo para actualizar que ya se subio documentación
    public function setDocs($id)
    {

        //Verificar si ya se subio todos sus documentos, además de verificar la vigencia la vigencia de las fechas de los documentos
        //Hacer un left join de los documentos del chofer con la tabla intermediaria, si hay valores nulos quiere decir que no ha subido algun documento
        $documentos = DB::select("SELECT d.*, ud.valor, u.idUser, u.nombreCompleto
                            FROM document d
                            LEFT JOIN user_document ud ON d.idDocs = ud.idDocs AND ud.idUser = ?
                            LEFT JOIN user u ON u.idUser = ud.idUser", [$id]);

        $banDocumentos = false;
        foreach ($documentos as $doc) {
            
            //Si el campo valor es nulo, quiere decir que no hay documento
            if ($doc->valor === null) {
                $banDocumentos = false;
                break;
            } else {
                $banDocumentos = true;
            }
        }

        //Si subio los documentos, verificar que los campos de tipo fecha esten vigentes
        if ($banDocumentos) {
            $banVigente = false;
            foreach ($documentos as $doc) {
                if ($doc->tipo === 'date') {
                    $fechaDoc = Carbon::parse($doc->valor)->format('Y-m-d');
                    $fechaActual = Carbon::parse(Carbon::now()->format('Y-m-d'));
                    if ($fechaActual->greaterThanOrEqualTo($fechaDoc)) {
                        $banVigente = false;
                        return $this->errorResponse('Por favor, verifique que las fechas que ingreso esten vigentes.', 400);
                        break;
                    } else {
                        $banVigente = true;
                    }
                }
            }

            if ($banVigente) {
                $user = User::findOrFail($id);
                $user->subirDocs = true;
                if ($user->save()) {

                    //Checar los demás requerimientos, en caso de que ya se hayan cumplido, activar al usuario
                    $video = $user->verVideo;
                    $quiz = $user->contestarQuiz;
                    $docs = $user->subirDocs;
                    if ($video == 1 && $quiz == 1 && $docs == 1) {
                        $user->estatus = 'activo';
                        $user->save();
                    }
                    return $this->successResponse(null, 'Has terminado de subir o de actualizar tu documentación.');
                } else {
                    return $this->errorResponse('Error al actualizar', 500);
                }
            }
        } else {
            return $this->errorResponse('Por favor, verifique que haya subido toda la documentación solicitada.', 400);
        }
    }

    //Metodo para obtener información basica del chofer
    public function basicChofer($id)
    {

        $choferInfo = User::select('clave', 'nombreCompleto', 'foto', 'estatus', 'lastQuizDate')
            ->where([['idUser', $id], ['rol', 'chofer']])
            ->firstOrFail();

        return (new BasicChoferResource($choferInfo))->additional([
            'msg' => null
        ])->response()->setStatusCode(200);
    }

    //Metodo para obtener informacion basica del administrador o los demas roles
    public function basicAdmin(Request $request, $id)
    {
        $rol = $request->rol;
        $adminInfo = User::select('clave', 'nombreCompleto', 'rol', 'foto')
            ->where([['idUser', $id], ['rol', $rol]])
            ->firstOrFail();
        return (new BasicAdminResource($adminInfo))->additional([
            'msg' => null
        ])->response()->setStatusCode(200);
    }

    //Metodo para obtener el estado actual del chofer e informacion basica
    public function choferStatus($id)
    {
        $choferStatus = User::select('nombreCompleto', 'edad', 'estadoProcedencia', 'foto', 'estatus')
            ->where([['idUser', $id], ['rol', 'chofer']])
            ->firstOrFail();
        return (new StatusChoferResource($choferStatus))->additional([
            'msg' => null
        ])->response()->setStatusCode(200);
    }

    //Metodo para obtener informacion basica de todos los choferes
    public function getChoferes()
    {
        $choferes = User::select('idUser', 'nombreCompleto', 'estatus',  'foto')
            ->where([['rol', 'chofer']])
            ->get();

        return BasicAllChoferesResource::collection($choferes)->additional([
            'res' => true,
            'msg' => null
        ])->response()->setStatusCode(200);
    }

    //Metodo para obtener el detalle de un chofer
    public function detailChofer($id)
    {
        $chofer = User::select(
            'nombreCompleto',
            'clave',
            'rol',
            'fechaNacimiento',
            'edad',
            'fechaRegistro',
            'genero',
            'estadoProcedencia',
            'foto',
            'telefonoCelular',
            'estatus',
            'verVideo',
            'contestarQuiz',
            'subirDocs'
        )
            ->where([['idUser', $id]])
            ->firstOrFail();

        return (new DetailChoferResource($chofer))->additional([
            'msg' => null
        ])->response()->setStatusCode(200);
    }

    //Metodo para actualizar el estatus de un chofer y deshabilitarlo
    public function deshabilitarChofer(Request $request, $id)
    {
        $request->validate([
            'motivo' => 'required'
        ]);

        $idUser = $id;
        $motivo = $request->motivo;
        DB::transaction(function () use ($idUser, $motivo) {
            //Actualiza su estatus a vetado
            User::where('idUser', $idUser)->update(['estatus' => 'vetado']);

            //Registrar el evento indicando la razon por la cual se deshabiliato el chofer
            Event::create([
                'fecha' => date("Y-m-d H:i:s"),
                'observaciones' => $motivo,
                'FK_idUser' => $idUser
            ]);
        });
        return $this->successResponse(null, 'Estatus actualizado correctamente.');
    }

    //Metodo para actualizar el estatus de un chofer y habilitarlo nuevamente
    public function habilitarChofer($id)
    {
        $user = User::findOrFail($id);
        //Poner como activo solo si ya cumplio requerimiento, en caso contrario ponerlo como incompleto
        if($user->verVideo == true && $user->contestarQuiz == true && $user->subirDocs == true) {
            $user->estatus = 'activo';
            if ($user->save()) {
                return $this->successResponse(null, 'Estatus actualizado correctamente.');
            } else {
                return $this->errorResponse('Error al habilitar chofer', 500);
            }
        } else {
            $user->estatus = 'incompleto';
            if ($user->save()) {
                return $this->successResponse(null, 'Estatus actualizado correctamente.');
            } else {
                return $this->errorResponse('Error al habilitar chofer', 500);
            }
        }
        
    }


    //Metodo para obtener informacion de todos los usuario que no son choferes
    public function noChoferes()
    {
        $usuarios = User::select('idUser', 'clave', 'nombreCompleto', 'rol', 'foto')
            ->where([['rol', '!=', 'chofer']])
            ->get();

        return NoChoferesResource::collection($usuarios)->additional([
            'res' => true,
            'msg' => null
        ])->response()->setStatusCode(200);
    }

    //Metodo para obtener un usuario que no es chofer por su id
    public function getUsuarioById($id)
    {
        $usuario = User::select('nombreCompleto', 'rol')
            ->where('idUser', $id)
            ->firstOrFail();
        return $this->successResponse($usuario);
    }

    //Metodo para guardar un nuevo usuario que no sea chofer
    public function saveUser(Request $request)
    {
        $request->validate([
            'rol' => 'required',
            'nombreCompleto' => 'required',
            //'foto' => 'required|mimes:jpeg,png'
        ]);

        //Generar al nuevo usuario
        $clave = 'cym' . explode(" ", $request->nombreCompleto)[0] . '_' . uniqid();
        $user = new User();
        $user->clave = $clave;
        $user->password = bcrypt('cym12345678');
        $user->rol = $request->rol;
        $user->nombreCompleto = $request->nombreCompleto;
        $user->foto = 'admin_icon.jpg';

        if ($user->save()) {
            return $this->successResponse(null, 'Usuario registrado correctamente');
        } else {
            return $this->errorResponse('Hubo el error al registrar al usuario', 500);
        }
    }

    //Metodo para eliminar algun usuario que no es chofer
    public function deleteUser(Request $request, $id)
    {
        $usuario = User::findOrFail($id);
        $usuario->delete();
        return $this->successResponse(null, 'Usuario eliminado correctamente.');
    }

    //Metodo para actualizar a un usuario que no es chofer
    public function editUser(Request $request, $id)
    {
        $usuario = User::findOrFail($id);
        $usuario->update($request->all());
        return $this->successResponse(null, 'Usuario actualizado correctamente.');
    }


    //Metodo para obtener los choferes que tengan sus fechas no vigentes de sus documentos
    public function getChoferesDocsNoVigentes() {
        //Obtener a todos los choferes que tenga documentos con fechas vencidas
        $data = User::select('idUser', 'clave', 'nombreCompleto')->where('rol', 'chofer')
            ->has('documents')
            ->with(['documents' => function($query) {
                $query->select('document.idDocs', 'document.nombre')
                    ->where('tipo', 'date')
                    ->wherePivot('valor', '<=', Carbon::now()->format('Y-m-d'));
            }])->get();
        
        //Filtrar el resultado, ya que es posible obtener usuarios con una lista de fechas de documentos vacias
        $data = collect($data);
        $data = $data->filter(function($chofer) {
            return count($chofer->documents) > 0;
        });
        
        return $this->successResponse($data->values()->all());
    }
}
