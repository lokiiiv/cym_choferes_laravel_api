<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\ApiController;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class DocumentController extends ApiController
{
    public function __construct()
    {
        $this->middleware('jwt.verify');
    }
    
    //Metodo para obtener que documentos son lo que estan permitidos subir por parte de los choferes
    public function docsDisponibles(Request $request) {
        $docs = Document::all();
        return $this->successResponse($docs);
    }

    public function docsPorChofer(Request $request, $id) {
        $query = DB::select("SELECT d.*, ud.valor, u.idUser, u.nombreCompleto
                            FROM document d
                            LEFT JOIN user_document ud ON d.idDocs = ud.idDocs AND ud.idUser = ?
                            LEFT JOIN user u ON u.idUser = ud.idUser", [$id]);
        
        return $this->successResponse($query);
    }

    //Metodo para almacenar y guardar un tipo de documento que el chofer debe subir
    public function nuevoDocumento(Request $request){
        $request->validate([
            'tipo' => 'required',
            'nombre' => 'required'
        ]);
        $tipo = $request->tipo;
        $nombre = $request->nombre;
        $verificarVigencia = $request->isVigente;

        $document = new Document();
        $document->tipo = $tipo;
        $document->nombre = $nombre;
        $document->verificarVigencia = $tipo == 'date' ? $verificarVigencia : null;

        setlocale(LC_ALL, 'en_US.UTF8');
        $var= preg_replace("/[^A-Za-z0-9 ]/", '',iconv('UTF-8', 'ASCII//TRANSLIT', $nombre));
        $document->metadata = Str::upper(str_replace(' ', '_', $var));
        if($document->save()) {

            //Tambien, poner a los choferes en estatus incompleto debido a que deben subir un nuevo documento
            User::where('rol', 'chofer')-> update(['subirDocs' => false, 'estatus' => 'incompleto']);

            return $this->successResponse(null, 'Documento agregado exitosamente.');
        } else {
            return $this->errorResponse('Error al agregar dato.', 500);
        }
    }


    //Metodo para subir los documentos al servidor
    public function uploadDocs(Request $request) {
        $request->validate([
            'idUser' => 'required',
            'file' => 'required',
            'idDoc' => 'required',
            'tipoDoc' => 'required',
            'nombre' => 'required',
            'nameUser' => 'required',
            'nombreFile' => 'required'
        ]);
        $idUser = $request->idUser;
        $userName = $request->nameUser;

        $tipoDoc = $request->tipoDoc;
        $idDoc = $request->idDoc;

        //Verificar si el archivo o dato ya se encuentra almacenado en la base de datos
        $user = User::find($idUser);
        $doc = $user->documents()->where('user_document.idDocs', $idDoc)->first();
        if($doc) {

            if($tipoDoc == 'file'){
                //Obtener el directorio y eliminar el documento actual
                $directorio = $doc->pivot->valor;
                Storage::disk('documents')->delete($directorio);

                //Proceder a almacenar el nuevo documento y actualizar en la base de datos
                $extension = $request->file('file')->getClientOriginalExtension();
                //Generar un nombre unico
                $fileName = microtime(true) . '_' . $request->nombre . '_' . $idUser . '_' . $userName . '.' . $extension;
                $file = File::get($request->file('file'));
                //Definir el directorio
                $directorio = $idUser . '/' . $fileName;
                //Subir el archivo
                Storage::disk('documents')->put($directorio, $file);

                $user->documents()->updateExistingPivot($idDoc, [
                    'valor' => $directorio
                ]);

                return $this->successResponse(null, 'Documento registrado correctamente: ' . $request->nombreFile . '.');
            } else {
                $user->documents()->updateExistingPivot($idDoc, [
                    'valor' => $request->file
                ]);
                return $this->successResponse(null, 'Documento registrado correctamente: ' . $request->nombreFile . '.');
            }

        } else {

            if($tipoDoc === 'file') {
                //Aun no existe el documento, crear direcotrio y subirlo
                if(Storage::disk('documents')->makeDirectory($idUser, 0777, true, true)) {
                    //subir el archivo correspondiente
                    $extension = $request->file('file')->getClientOriginalExtension();
                    //Generar un nombre unico
                    $fileName = microtime(true) . '_' . $request->nombre . '_' . $idUser . '_' . $userName . '.' . $extension;
                    $file = File::get($request->file('file'));
                    //Definir el directorio
                    $directorio = $idUser . '/' . $fileName;

                    //Subir el archivo
                    Storage::disk('documents')->put($directorio, $file);

                    //Almacenar en la base de datos
                    $user->documents()->attach($idDoc, ['valor' => $directorio]);
                    return $this->successResponse(null, 'Documento registrado correctamente: ' . $request->nombreFile . '.');
                }
            } else {
                $user->documents()->attach($idDoc, ['valor' => $request->file]);
                return $this->successResponse(null, 'Documento registrado correctamente: ' . $request->nombreFile . '.');
            }
        }
    }

    //Metodo para eliminar un documento que el administrador no desea que el chofer suba más
    public function deleteDocument(Request $request, $id){
        //Obtener el id del documento a eliminar
        $idDoc = $id;

        $archivosAEliminar = [];
        //Recorrer a todos los usuarios que son choferes para obtener sus documentos
        $users = User::select('idUser')->where('rol', 'chofer')->get();
        foreach ($users as $key => $value) {
            //Obtener el documento cuyo ID es el que se quiere eliminar de cada usuario
            $doc = User::find($value['idUser'])->documents()->where('user_document.idDocs', $idDoc)->first();
            if($doc) {
                if($doc->tipo == 'file') {
                    array_push($archivosAEliminar, $doc->pivot->valor);
                }
            }
        }
        //Una vez que se tienen las rutas de los archivos, eliminarlos
        foreach ($archivosAEliminar as $value) {
            Storage::disk('documents')->delete($value);
        }
        
        //Una vez eliminador los archivos, eliminar el registro del archivo en la base de datos
        $document = Document::findOrFail($idDoc);
        $document->delete();

        //Es posible que al eliminar un documento, haya usuarios que no lo hayan subido aún
        //Si este documento se elimina en el momento en que los usuarios no lo hayan subido, es necesario poner a estos usuario como activos nuevamente
        foreach($users as $user) {
            //Obtener una consulta para verificar si el usuario ya completo los documentos
            $documentos = DB::select("SELECT d.*, ud.valor, u.idUser, u.nombreCompleto
                            FROM document d
                            LEFT JOIN user_document ud ON d.idDocs = ud.idDocs AND ud.idUser = ?
                            LEFT JOIN user u ON u.idUser = ud.idUser", [$user['idUser']]);

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
            if($banDocumentos) {
                $usuario = User::findOrFail($user['idUser']);
                $usuario->estatus = 'activo';
                $usuario->subirDocs = true;
                $usuario->save();
            }
        }

        return $this->successResponse(null, 'Documento eliminado correctamente.');
    }

    //Metodo para obtener los documentos de un chofer
    public function getDocuments($id) {
        $chofer = User::findOrFail($id);
        $docs = $chofer->documents;
        //$docs = Document::where('FK_idUser', $id)->firstOrFail();
        
        return $this->successResponse($docs);
    }


    //Metodo para descargar cierto documento
    public function downloadDoc(Request $request) {
        $url = $request->get('url');
        
        //Verificar si el archivo existe
        if(Storage::disk('documents')->exists($url)){
            return Storage::disk('documents')->response($url);
        } else {
            return $this->errorResponse('El archivo que intenta descargar no existe.', 404);
        }
    }

    
}
