<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A demo é pública e sem login: cada visitante recebe um ID anônimo na sessão, que isola
 * suas conversas das de outros avaliadores. A loja atual é resolvida aqui, no servidor;
 * com autenticação, viria do usuário logado (ex.: $request->user()->store).
 */
class IdentifyVisitor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('visitor_id')) {
            $request->session()->put('visitor_id', (string) Str::uuid());
        }

        $store = Store::query()->find(config('app.demo_store_id'));
        abort_unless($store, 503, 'Base de demonstração não encontrada. Rode php artisan migrate --seed.');

        $request->attributes->set('store', $store);
        $request->attributes->set('visitor_id', $request->session()->get('visitor_id'));

        return $next($request);
    }
}
