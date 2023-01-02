<?php

use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DocumentController;
use App\Http\Controllers\API\QuizController;
use App\Http\Controllers\API\RouteController;
use App\Http\Controllers\API\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::group([
    'middleware' => 'api',
    'prefix' => 'auth'
], function($router) {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'user'
], function($router) {
    Route::get('/requerimientos/{idUser}', [UserController::class, 'requerimientos']);
    Route::put('/requerimientos/update/video/{idUser}', [UserController::class, 'setVideo']);
    Route::put('/requerimientos/update/quiz/{idUser}', [UserController::class, 'setQuiz']);
    Route::put('/requerimientos/update/docs/{idUser}', [UserController::class, 'setDocs']);
    Route::get('/info/chofer/basic/{idUser}', [UserController::class, 'basicChofer']);
    Route::post('/info/admin/basic/{idUser}', [UserController::class, 'basicAdmin']);
    Route::get('/estatus/chofer/{idUser}', [UserController::class, 'choferStatus']);
    Route::get('/all/choferes', [UserController::class, 'getChoferes']);
    Route::get('/chofer/detail/{idUser}', [UserController::class, 'detailChofer']);
    Route::put('/chofer/deshabilitar/{idUser}', [UserController::class, 'deshabilitarChofer']);
    Route::put('/chofer/habilitar/{idUser}', [UserController::class, 'habilitarChofer']);

    Route::get('/no-choferes', [UserController::class, 'noChoferes']);
    Route::get('/no-chofer/get/{idUser}', [UserController::class, 'getUsuarioById']);
    Route::post('/save-no-chofer', [UserController::class, 'saveUser']);
    Route::delete('/delete/{idUser}', [UserController::class, 'deleteUser']);
    Route::put('/edit/{idUser}', [UserController::class, 'editUser']);

    Route::get('/docs/no-vigentes', [UserController::class, 'getChoferesDocsNoVigentes']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'quiz'
], function($router) {
    Route::get('/detail/{id}', [QuizController::class, 'detailQuiz']);
    Route::post('/correct', [QuizController::class, 'isCorrectAnswer']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'attendance'
], function($router) {
    Route::get('/last-attendance/{idUser}', [AttendanceController::class, 'lastAttendance']);
    Route::post('/check-in/{idUser}', [AttendanceController::class, 'checkIn']);
    Route::post('/check-out/{idUser}', [AttendanceController::class, 'checkOut']);
    Route::get('/chofer/{idUser}', [AttendanceController::class, 'allAttendancesChofer']);
    Route::get('/all', [AttendanceController::class, 'attendances']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'document'
], function($router) {
    Route::get('/get', [DocumentController::class, 'docsDisponibles']);
    Route::get('/get/permitidos/{idChofer}', [DocumentController::class, 'docsPorChofer']);
    Route::post('/add', [DocumentController::class, 'nuevoDocumento']);
    Route::post('/upload', [DocumentController::class, 'uploadDocs']);
    Route::delete('/delete/{id}', [DocumentController::class, 'deleteDocument']);
    Route::get('/get/{idUser}', [DocumentController::class, 'getDocuments']);
    
    Route::get('/download', [DocumentController::class, 'downloadDoc']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'ruta'
], function($router) {

    //Metodo para descargar las asistencias de las rutas de cebada, malta, coproducto
    Route::get('/download', [RouteController::class, 'downloadAsistencias']);

    Route::post('/nueva', [RouteController::class, 'nuevaEntrada']);
    Route::get('/todas', [RouteController::class, 'getAllRutas']);

    //Metodo para registrar un movimiento de cebada por primera vez
    Route::post('/cebada/agregar/{id}', [RouteController::class, 'registrarMovimientoCebada']);
    //Metodo para registrar un movimiento de cebada si el chofer esta como estatus incompleto
    Route::post('/cebada/agregar/incompleto/{id}', [RouteController::class, 'registrarMovimientoCebadaIncompleto']);
    //Metodo para registrar un movimiento de cebada en caso de que algun movmiento anterior no se haya registrado o bien se olvido registrar
    Route::post('/cebada/agregar/forzar/{id}', [RouteController::class, 'registrarMovimientoCebadaForzar']);


    Route::post('/malta/agregar/{id}', [RouteController::class, 'registrarMovimientoMalta']);
    Route::post('/malta/agregar/incompleto/{id}', [RouteController::class, 'registrarMovimientoMaltaIncompleto']);
    Route::post('/malta/agregar/forzar/{id}', [RouteController::class, 'registrarMovimientoMaltaForzar']);

    Route::post('/coproducto/agregar/{id}', [RouteController::class, 'registrarMovimientoCoproducto']);
    Route::post('/coproducto/agregar/incompleto/{id}', [RouteController::class, 'registrarMovimientoCoproductoIncompleto']);
    Route::post('/coproducto/agregar/forzar/{id}', [RouteController::class, 'registrarMovimientoCoproductoForzar']);

});
