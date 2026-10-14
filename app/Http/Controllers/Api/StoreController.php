<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Troca de estabelecimento. A loja escolhida fica na SESSÃO (servidor) e é validada aqui;
 * a IA nunca recebe nem escolhe a loja — ela só vê os dados da loja que o servidor definiu.
 * Num produto com login, a lista viria das lojas a que o usuário tem acesso.
 */
class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Store::query()->orderBy('id')->get(['id', 'name']),
            'current_id' => $request->attributes->get('store')->id,
        ]);
    }

    public function select(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);

        $request->session()->put('store_id', $validated['store_id']);

        return response()->json(['current_id' => $validated['store_id']]);
    }
}
