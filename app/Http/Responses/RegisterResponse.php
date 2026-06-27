<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  \Illuminate\Http\Request  $request
     */
    public function toResponse($request)
    {
        $redirectUrl = route('dashboard');

        if ($request->wantsJson()) {
            return new JsonResponse(['redirect' => $redirectUrl], 201);
        }

        return redirect($redirectUrl);
    }
}
