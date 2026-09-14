<?php

namespace App\Http\V1\Controllers;

use App\Http\V1\Requests\GuardarSuplidorRequest;
use App\Models\Suplidor;
use App\Soporte\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class SuplidorController extends Controller
{
    /** SUP-03: búsqueda, orden y paginación, siempre en el servidor (RNF-02). */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos', 'todos'])],
            'orden' => ['nullable', Rule::in(Suplidor::ORDENABLES)],
            'direccion' => ['nullable', Rule::in(['asc', 'desc'])],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $orden = $filtros['orden'] ?? 'razon_social';
        $direccion = $filtros['direccion'] ?? 'asc';
        $estado = $filtros['estado'] ?? 'activos';

        $consulta = Suplidor::query()
            ->buscar($filtros['buscar'] ?? null)
            ->when($estado === 'activos', fn ($q) => $q->where('activo', true))
            ->when($estado === 'inactivos', fn ($q) => $q->where('activo', false))
            ->orderBy($orden, $direccion)
            ->orderBy('id');   // desempate estable para el cursor

        $pagina = $consulta->cursorPaginate($filtros['por_pagina'] ?? 25);

        return response()->json([
            'datos' => collect($pagina->items())->map(fn (Suplidor $s) => $this->comoArreglo($s))->all(),
            'siguiente' => $pagina->nextCursor()?->encode(),
            'anterior' => $pagina->previousCursor()?->encode(),
            'total_visible' => $pagina->count(),
            'permisos' => [
                'editar' => Permisos::permite($request->attributes->get('membresia'), Permisos::CATALOGO_EDITAR),
                'desactivar' => Permisos::permite($request->attributes->get('membresia'), Permisos::CATALOGO_DESACTIVAR),
            ],
        ]);
    }

    public function store(GuardarSuplidorRequest $request): JsonResponse
    {
        // empresa_id lo pone el contexto, no la petición (SEG-14).
        $suplidor = Suplidor::create($request->validated());

        return response()->json($this->comoArreglo($suplidor), 201);
    }

    public function update(GuardarSuplidorRequest $request, Suplidor $suplidor): JsonResponse
    {
        $suplidor->update($request->validated());

        return response()->json($this->comoArreglo($suplidor));
    }

    /** SUP-04: se desactiva, no se borra. El historial de compras sigue en pie. */
    public function desactivar(Suplidor $suplidor): JsonResponse
    {
        $suplidor->update(['activo' => false]);

        return response()->json($this->comoArreglo($suplidor));
    }

    public function reactivar(Suplidor $suplidor): JsonResponse
    {
        $suplidor->update(['activo' => true]);

        return response()->json($this->comoArreglo($suplidor));
    }

    /** Solo los campos que la pantalla necesita, nunca el modelo completo (ARQ-20). */
    private function comoArreglo(Suplidor $s): array
    {
        return [
            'id' => $s->ulid,
            'razon_social' => $s->razon_social,
            'nombre_comercial' => $s->nombre_comercial,
            'numero_cliente' => $s->numero_cliente,
            'telefono' => $s->telefono,
            'email' => $s->email,
            'vendedor' => $s->vendedor,
            'terminos_pago' => $s->terminos_pago,
            'notas' => $s->notas,
            'activo' => $s->activo,
        ];
    }
}
