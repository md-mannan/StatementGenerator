<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * @param  \Illuminate\Http\Request  $request
     */
    public function toResponse($request)
    {
        $redirectUrl = route('dashboard');

        if ($request->wantsJson()) {
            return new JsonResponse(['redirect' => $redirectUrl]);
        }

        return redirect()->intended($redirectUrl);
    }
}
