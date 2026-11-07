<?php

namespace Fixture\Http\Controllers;

class HomeController
{
    /** The logged-in user arrives from SESAME; the app never reads headers. */
    public function index(string $user): string
    {
        return $user;
    }
}
