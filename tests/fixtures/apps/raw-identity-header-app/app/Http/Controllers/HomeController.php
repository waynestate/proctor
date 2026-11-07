<?php

namespace Fixture\Http\Controllers;

class HomeController
{
    public function index($request, $psrRequest)
    {
        $user = $_SERVER['REMOTE_USER'];
        $proxyUser = $_SERVER['HTTP_X_AUTHENTICATED_USER'];
        $envUser = $_ENV['AUTH_USER'];
        $claim = getenv('OIDC_CLAIM_email');
        $apacheClaim = apache_getenv('X-Drawbridge-Role');
        $laravelHeader = $request->header('X-Authenticated-User');
        $bagHeader = $request->headers->get('X-Drawbridge-Department');
        $hasIt = $request->hasHeader('x-authenticated-user');
        $line = $psrRequest->getHeaderLine('X-Remote-User');
        $all = getallheaders();
        $basicClaim = $all['AUTH_USER'];
        $token = apache_request_headers()['OIDC_access_token'];
        $serverRead = $request->server('HTTP_REMOTE_USER');

        return [
            $user,
            $proxyUser,
            $envUser,
            $claim,
            $apacheClaim,
            $laravelHeader,
            $bagHeader,
            $hasIt,
            $line,
            $basicClaim,
            $token,
            $serverRead,
        ];
    }
}
