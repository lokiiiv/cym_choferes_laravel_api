<?php

namespace App\Http\Controllers\API;

use App\Http\Requests\SaveUserRequest;
use App\Http\Requests\LoginUserRequest;
use App\Http\Resources\AuthResource;
use App\Models\User;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\ApiController;

class AuthController extends ApiController
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('jwt.verify', ['except' => ['login', 'register', 'refresh']]);
    }

    //Metodo para registrar a un nuevo chofer
    public function register(SaveUserRequest $request) {

        //Generar la clave de usuario
        $clave = 'cym' . explode(" ", $request->nombre)[0] . '_' . uniqid();

        //Verificar si ya existe un usuario con la clave generada
        $user = User::where('clave', $clave)->first();
        if(!$user) {

            //Generar algunos datos importantes
            $password = 'cym12345678';
            $passwordHash = '';
            $refreshToken = '';

            $rol = 'chofer';
            $fechaRegistro = date('Y-m-d');
            //get age from date or birthdate
            $birthdate = new DateTime($request->fechaNacimiento);
            $today   = new DateTime('today');
            $age = $birthdate->diff($today)->y;

            $foto = "";
            $estatus = 'incompleto';
            $verVideo = false;
            $contestarQuiz = false;
            $subirDocs = false;

            //Generar la imagen de perfil recibida en base64 y guardarlo en el servidor
            $dataImage = base64_decode($request->base64image);
            $foto = $clave . '_profile_photo.jpeg';
            Storage::disk('avatars')->put($foto, $dataImage);
            
            $user = new User();
            $user->clave = $clave;
            $user->password = bcrypt($password);
            $user->rol = $rol;
            $user->nombreCompleto = $request->nombre;
            $user->fechaNacimiento = $request->fechaNacimiento;
            $user->edad = $age;
            $user->fechaRegistro = $fechaRegistro;
            $user->genero = $request->genero;
            $user->estadoProcedencia = $request->estado;
            $user->foto = $foto;
            $user->telefonoCelular = $request->telefono;
            $user->estatus = $estatus;
            $user->verVideo = $verVideo;
            $user->contestarQuiz = $contestarQuiz;
            $user->subirDocs = $subirDocs;
            
            if($user->save()) {
                
                 //Generar el JWT del usuario autenticado usando la libreria de jwt para laravel
                $accessToken = auth()->login($user);

                return (new AuthResource($user, $accessToken))->additional([
                    'msg' => 'Usuario registrado correctamente'
                ])->response()->setStatusCode(200);

            } else {
                return $this->errorResponse('Error al registrar el usuario.', 500);
            }
        } else {
            return $this->errorResponse('Ya existe un usuario con la clave generada.', 409);
        }
    }

    //Metodo para iniciar sesión
    public function login(LoginUserRequest $request) {
        //Buscar al usuario por su contraseña y clave de usuario

        // $user = User::where('clave', $request->clave)
        //               ->where('password', $request->password)
        //               ->first();
        // if(!$user) {
        //     return $this->errorResponse('No existe un usuario con las datos ingresados.', 404);
        // }

        //Generar el JWT del usuario autenticado usando la libreria de jwt para laravel
        if( !$token = auth()->attempt($request->only('clave', 'password'))){
            return $this->errorResponse('No existe un usuario con las datos ingresados.', 404);
        }
        
        //$accessToken = auth()->login($user);
        return (new AuthResource(auth()->user(), $token))->additional([
            'msg' => 'Bienvenido ' . auth()->user()->nombreCompleto
        ])->response()->setStatusCode(200);
    }

     /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout() {
        auth()->logout(true);
        return $this->successResponse(null, 'Has cerrado sesión correctamente.');
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh() {
        return $this->successResponse(['accessToken' => auth()->refresh(true, true)], null);
    }

     /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function userProfile() {
        return response()->json(auth()->user());
    }
}
