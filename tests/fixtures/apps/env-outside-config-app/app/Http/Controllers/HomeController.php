<?php

namespace App\Http\Controllers;

class HomeController
{
    public function __invoke(): string
    {
        // env('IN_A_COMMENT') is not a call
        $text = "env('IN_A_STRING')";
        $mode = app()->environment();
        $url = env('APP_URL');

        return $this->env('METHOD') . $url . $text . $mode;
    }

    private function env(string $name): string
    {
        return $name;
    }
}
