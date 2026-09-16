<?php

namespace App\Http\V1\Controllers;

use App\Domain\Precio;
use App\Models\Cliente;
use App\Soporte\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', Rule::in(Cliente::TIPOS)],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos', 'todos'])],
            'con_credito' => ['nullable', 'boolean'],
            'orden' => ['nullable', Rule::in(Cliente::ORDENABLES)],
            'direccion' => ['nullable', Rule::in(['asc', 'desc'])],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $estado = $filtros['estado'] ?? 'activos';
        $membresia = $request->attributes->get('membresia');

        $pagina = Cliente::query()
            ->buscar($filtros['buscar'] ?? null)
            ->when($estado === 'activos', fn ($q) => $q->where('activo', true))
            ->when($estado === 'inactivos', fn ($q) => $q->where('activo', false))
            ->when($filtros['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($request->boolean('con_credito'), fn ($q) => $q->where('limite_credito_centavos', '>', 0))
            ->orderBy($filtros['orden'] ?? 'nombre', $filtros['direccion'] ?? 'asc')
            ->orderBy('id')
            ->cursorPaginate($filtros['por_pagina'] ?? 25);

        return response()->json([
            'datos' => collect($pagina->items())->map(fn (Cliente $c) => $this->comoArreglo($c))->all(),
            'siguiente' => $pagina->nextCursor()?->encode(),
            'anterior' => $pagina->previousCursor()?->encode(),
            'total_visible' => $pagina->count(),
            'tipos' => Cliente::TIPOS,
            'permisos' => [
                'editar' => Permisos::permite($membresia, Permisos::CLIENTES_EDITAR),
                'desactivar' => Permisos::permite($membresia, Permisos::CATALOGO_DESACTIVAR),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $cliente = Cliente::create($this->validar($request));

        return response()->json($this->comoArreglo($cliente), 201);
    }

    public function update(Request $request, Cliente $cliente): JsonResponse
    {
        $cliente->update($this->validar($request, $cliente));

        return response()->json($this->comoArreglo($cliente->fresh()));
    }

    public function desactivar(Cliente $cliente): JsonResponse
    {
        $cliente->update(['activo' => false]);

        return response()->json($this->comoArreglo($cliente));
    }

    public function reactivar(Cliente $cliente): JsonResponse
    {
        $cliente->update(['activo' => true]);

        return response()->json($this->comoArreglo($cliente));
    }

    private function validar(Request $request, ?Cliente $cliente = null): array
    {
        $datos = $request->validate([
            // CLI-02: al vuelo desde la caja basta el nombre.
            'nombre' => ['required', 'string', 'min:2', 'max:150'],
            'tipo' => ['nullable', Rule::in(Cliente::TIPOS)],
            'identificacion' => [
                'nullable', 'string', 'max:40',
                Rule::unique(Cliente::class, 'identificacion')
                    ->whereNull('borrado_en')
                    ->ignore($cliente?->id),
            ],
            'telefono' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 ()+\-.]{7,30}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'exento' => ['boolean'],
            // Sin número, la exención no se sostiene ante una auditoría (CLI-01).
            'certificado_exencion' => [
                Rule::requiredIf(fn () => $request->boolean('exento')),
                'nullable', 'string', 'max:60',
            ],
            'terminos_pago' => ['nullable', 'string', 'max:60'],
            'limite_credito' => ['nullable', 'string', 'max:20'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ], [
            'certificado_exencion.required' => 'Si el cliente es exento, hace falta el número de su certificado.',
            'telefono.regex' => 'El teléfono solo puede llevar números, espacios, paréntesis, guiones y el signo más.',
            'identificacion.unique' => 'Ya tienes otro cliente con esa identificación.',
        ], [
            'identificacion' => 'identificación',
            'direccion' => 'dirección',
            'certificado_exencion' => 'número de certificado',
            'terminos_pago' => 'términos de pago',
            'limite_credito' => 'límite de crédito',
        ]);

        $limite = Precio::aCentavos($datos['limite_credito'] ?? null);

        if (($datos['limite_credito'] ?? '') !== '' && $limite === null) {
            abort(response()->json([
                'message' => 'El límite de crédito tiene que ser un número.',
                'errors' => ['limite_credito' => ['El límite de crédito tiene que ser un número.']],
            ], 422));
        }

        $exento = $request->boolean('exento');

        return [
            'nombre' => $datos['nombre'],
            'tipo' => $datos['tipo'] ?? 'persona',
            'identificacion' => $datos['identificacion'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'email' => $datos['email'] ?? null,
            'direccion' => $datos['direccion'] ?? null,
            'exento' => $exento,
            // Si deja de ser exento, el certificado se va con él: un número
            // suelto en la ficha se acaba usando por error.
            'certificado_exencion' => $exento ? ($datos['certificado_exencion'] ?? null) : null,
            'terminos_pago' => $datos['terminos_pago'] ?? null,
            'limite_credito_centavos' => $limite ?? 0,
            'notas' => $datos['notas'] ?? null,
        ];
    }

    private function comoArreglo(Cliente $c): array
    {
        return [
            'id' => $c->ulid,
            'nombre' => $c->nombre,
            'tipo' => $c->tipo,
            'identificacion' => $c->identificacion,
            'telefono' => $c->telefono,
            'email' => $c->email,
            'direccion' => $c->direccion,
            'exento' => $c->exento,
            'certificado_exencion' => $c->certificado_exencion,
            'terminos_pago' => $c->terminos_pago,
            'limite_credito' => Precio::aTexto($c->limite_credito_centavos),
            'tiene_credito' => $c->tieneCredito(),
            'notas' => $c->notas,
            'activo' => $c->activo,
        ];
    }
}
