<?php

namespace App\Http\V1\Controllers;

use App\Models\Categoria;
use App\Models\Producto;
use App\Soporte\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CategoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'orden' => ['nullable', Rule::in(Categoria::ORDENABLES)],
            'direccion' => ['nullable', Rule::in(['asc', 'desc'])],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $pagina = Categoria::query()
            ->buscar($filtros['buscar'] ?? null)
            ->orderBy($filtros['orden'] ?? 'nombre', $filtros['direccion'] ?? 'asc')
            ->orderBy('id')
            ->cursorPaginate($filtros['por_pagina'] ?? 50);

        $conteos = $this->conteoDeProductos(collect($pagina->items())->pluck('id')->all());

        return response()->json([
            'datos' => collect($pagina->items())
                ->map(fn (Categoria $c) => $this->comoArreglo($c) + ['productos' => (int) ($conteos[$c->id] ?? 0)])
                ->all(),
            'siguiente' => $pagina->nextCursor()?->encode(),
            'anterior' => $pagina->previousCursor()?->encode(),
            'total_visible' => $pagina->count(),
            'colores' => Categoria::COLORES,
            'permisos' => [
                'editar' => Permisos::permite($request->attributes->get('membresia'), Permisos::CATALOGO_EDITAR),
                'desactivar' => Permisos::permite($request->attributes->get('membresia'), Permisos::CATALOGO_DESACTIVAR),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $categoria = Categoria::create($this->validar($request));

        return response()->json($this->comoArreglo($categoria), 201);
    }

    public function update(Request $request, Categoria $categoria): JsonResponse
    {
        $categoria->update($this->validar($request, $categoria));

        return response()->json($this->comoArreglo($categoria));
    }

    /**
     * CAT-03: una categoría con productos no se borra a secas. O se reasignan a
     * otra, o no se borra.
     */
    public function eliminar(Request $request, Categoria $categoria): JsonResponse
    {
        $enUso = Producto::query()->where('categoria_id', $categoria->id)->count();

        if ($enUso > 0 && ! $request->filled('reasignar_a')) {
            return response()->json([
                'message' => "Esta categoría tiene {$enUso} producto(s). Elige a cuál categoría pasarlos.",
                'codigo' => 'categoria_en_uso',
                'productos' => $enUso,
            ], 409);
        }

        if ($enUso > 0) {
            $datos = $request->validate([
                'reasignar_a' => ['required', 'string', 'size:26', 'different:'.$categoria->ulid],
            ]);

            $destino = Categoria::query()->where('ulid', $datos['reasignar_a'])->first();

            if (! $destino) {
                return response()->json(['message' => 'Esa categoría no existe.', 'codigo' => 'destino_invalido'], 404);
            }

            DB::transaction(function () use ($categoria, $destino) {
                Producto::query()->where('categoria_id', $categoria->id)->update(['categoria_id' => $destino->id]);
                $categoria->delete();
            });

            return response()->json(['mensaje' => 'Categoría eliminada y productos reasignados.', 'reasignados' => $enUso]);
        }

        $categoria->delete();

        return response()->json(['mensaje' => 'Categoría eliminada.', 'reasignados' => 0]);
    }

    /** Cuántos productos usa cada categoría, para el listado (CAT-02). */
    private function conteoDeProductos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Producto::query()
            ->selectRaw('categoria_id, count(*) as total')
            ->whereIn('categoria_id', $ids)
            ->groupBy('categoria_id')
            ->pluck('total', 'categoria_id')
            ->all();
    }

    private function validar(Request $request, ?Categoria $categoria = null): array
    {
        return $request->validate([
            'nombre' => [
                'required', 'string', 'min:2', 'max:80',
                // Sin distinguir mayúsculas, igual que el índice de la base: si
                // no, "dULCES" pasa la validación y revienta con un 500 (CAT-01).
                function (string $campo, mixed $valor, \Closure $fallar) use ($categoria) {
                    $repetida = Categoria::query()
                        ->when($categoria, fn ($q) => $q->where('id', '!=', $categoria->id))
                        ->whereRaw('lower(nombre) = ?', [mb_strtolower(trim((string) $valor))])
                        ->exists();

                    if ($repetida) {
                        $fallar('Ya tienes una categoría con ese nombre.');
                    }
                },
            ],
            'descripcion' => ['nullable', 'string', 'max:300'],
            // Paleta cerrada: nada de colores libres que queden ilegibles (SEG-13).
            'color' => ['nullable', Rule::in(Categoria::COLORES)],
        ], [], [
            'nombre' => 'nombre',
            'descripcion' => 'descripción',
        ]);
    }

    private function comoArreglo(Categoria $c): array
    {
        return [
            'id' => $c->ulid,
            'nombre' => $c->nombre,
            'descripcion' => $c->descripcion,
            'color' => $c->color,
        ];
    }
}
