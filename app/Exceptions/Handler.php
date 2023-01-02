<?php

namespace App\Exceptions;

use App\Traits\ApiResponser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class Handler extends ExceptionHandler
{
    use ApiResponser;
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    protected function invalidJson($request, ValidationException $exception) {
        return response()->json([
            'res' => false,
            'msg' => __('Los datos proporcionados no son válidos.'),
            'data' => $exception->errors(),
        ], $exception->status);
    }

    public function render($request, Throwable $exception) {
        if ($exception instanceof ModelNotFoundException) {
            return $this->errorResponse('Error, modelo o dato no encontrado.', 404);
        }
        if($exception instanceof MethodNotAllowedHttpException) {
            return $this->errorResponse('El método solicitado para la petición es invalido.', 405);
        }
        if($exception instanceof NotFoundHttpException) {
            return $this->errorResponse('La url especificada no puede ser encontrada.', 404);
        }

        if($exception instanceof HttpException) {
            return $this->errorResponse($exception->getMessage(), $exception->getStatusCode());
        }
        
        // if(config('app.debug')) {
        //     return parent::render($request, $exception);
        // }
        // return parent::render($request, $exception);

        return $this->errorResponse('Error inesperado, intente más tarde', 500);
    }
}
