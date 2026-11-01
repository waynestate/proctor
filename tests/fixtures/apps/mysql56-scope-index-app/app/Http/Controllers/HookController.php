<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

class HookController
{
    public function ok()
    {
        return response()->json(['ok' => true]);
    }

    public function fetch()
    {
        $resp = Http::get('https://example.test');

        return $resp->json();
    }
}
