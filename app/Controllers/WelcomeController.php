<?php

namespace App\Controllers;

use Vayu\Http\Request;
use Vayu\Http\Response;

class WelcomeController
{
    public function index(): Response
    {
        return view('coming-soon', ['launch' => '2026-11-25T00:00:00']);
    }

    public function hello(Request $request, string $name = 'vayu'): Response
    {
        return (new Response())->json([
            'framework' => 'Vayu',
            'message' => "Hello, {$name}!",
            'ip' => $request->ip(),
        ]);
    }
}
