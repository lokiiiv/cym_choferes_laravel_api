<?php

namespace App\Http\Middleware;

use Closure;
use JWTAuth;
use Illuminate\Http\Request;
use Exception;
use Tymon\JWTAuth\Http\Middleware\BaseMiddleware;
use App\Traits\ApiResponser;

class JWTMiddleware extends BaseMiddleware
{
    use ApiResponser;
    
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (Exception $e) {
            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException){
                return $this->errorResponse('No está autorizado para acceder a este recurso.', 401);
            }else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException){
                return $this->errorResponse('No está autorizado para acceder a este recurso.', 401);
            }else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenBlacklistedException){
                return $this->errorResponse('No está autorizado para acceder a este recurso.', 401);
            }else{
                return $this->errorResponse('No está autorizado para acceder a este recurso.', 401);
            }
        }
        return $next($request);
    }
}
