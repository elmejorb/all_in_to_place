<?php

namespace App\Http\V1\Controllers;

use App\Domain\Precio;
use App\Http\V1\Requests\GuardarProductoRequest;
use App\Models\Categoria;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Suplidor;
use App\Soporte\Inventario;
use App\Soporte\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class ProductoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'categoria' => ['nullable', 'string', 'size:26'],
            'suplidor' => ['nullable', 'string', 'size:26'],
            'existencia' => ['nullable', Rule::in(['agotado', 'bajo', 'normal'])],
            'tipo' => ['nullable', Rule::in(['producto', 'servicio'])],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos', 'todos'])],
            'orden' => ['nullable', Rule::in(Producto::ORDENABLES)],
            'direccion' => ['nullable', Rule::in(['asc', 'desc'])],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $estado = $filtros['estado'] ?? 'activos';
        $membresia = $request->attributes->get('membresia');
        $veCostos = Permisos::permite($membresia, Permisos::COSTOS_VER);

        $pagina = Producto::query()
            ->with(['categoria:id,ulid,nombre,color', 'suplidor:id,ulid,razon_social,nombre_comercial'])
            ->buscar($filtros['buscar'] ?? null)
            ->conExistencia($filtros['existencia'] ?? null)
            ->when($estado === 'activos', fn ($q) => $q->where('activo', true))
            ->when($estado === 'inactivos', fn ($q) => $q->where('activo', false))
            ->when(($filtros['tipo'] ?? null) === 'servicio', fn ($q) => $q->where('es_servicio', true))
            ->when(($filtros['tipo'] ?? null) === 'producto', fn ($q) => $q->where('es_servicio', false))
            ->when($filtros['categoria'] ?? null, fn ($q, $ulid) => $q->whereHas('categoria', fn ($c) => $c->where('ulid', $ulid)))
            ->when($filtros['suplidor'] ?? null, fn ($q, $ulid) => $q->whereHas('suplidor', fn ($s) => $s->where('ulid', $ulid)))
            ->orderBy($filtros['orden'] ?? 'nombre', $filtros['direccion'] ?? 'asc')
            ->orderBy('id')
            ->cursorPaginate($filtros['por_pagina'] ?? 25);

        return response()->json([
            'datos' => collect($pagina->items())->map(fn (Producto $p) => $this->comoArreglo($p, $veCostos))->all(),
            'siguiente' => $pagina->nextCursor()?->encode(),
            'anterior' => $pagina->previousCursor()?->encode(),
            'total_visible' => $pagina->count(),
            'unidades' => Producto::UNIDADES,
            'motivos_ajuste' => MovimientoInventario::MOTIVOS_AJUSTE,
            'permisos' => [
                'editar' => Permisos::permite($membresia, Permisos::CATALOGO_EDITAR),
                'desactivar' => Permisos::permite($membresia, Permisos::CATALOGO_DESACTIVAR),
                'ver_costos' => $veCostos,
            ],
        ]);
    }

    public function store(GuardarProductoRequest $request): JsonResponse
    {
        $producto = Producto::create($request->datosDelProducto());

        // La existencia inicial entra como movimiento de apertura, para que el
        // kardex tenga origen (INV-01, MIG-02).
        $inicial = (float) ($request->input('existencia_inicial') ?? 0);

        if (! $producto->es_servicio && $inicial > 0) {
            Inventario::registrar(
                $producto,
                'apertura',
                $inicial,
                'saldo de apertura',
                null,
                $producto->costo_centavos ?: null,
            );
        }

        return response()->json($this->comoArreglo($producto->fresh(['categoria', 'suplidor']), true), 201);
    }

    public function update(GuardarProductoRequest $request, Producto $producto): JsonResponse
    {
        $producto->update($request->datosDelProducto());

        return response()->json($this->comoArreglo($producto->fresh(['categoria', 'suplidor']), true));
    }

    public function desactivar(Producto $producto): JsonResponse
    {
        $producto->update(['activo' => false]);

        return response()->json($this->comoArreglo($producto, true));
    }

    public function reactivar(Producto $producto): JsonResponse
    {
        $producto->update(['activo' => true]);

        return response()->json($this->comoArreglo($producto, true));
    }

    /** INV-03: un ajuste manual siempre declara su motivo y queda registrado. */
    public function ajustar(Request $request, Producto $producto): JsonResponse
    {
        if ($producto->es_servicio) {
            return response()->json([
                'message' => 'Un servicio no lleva inventario.',
                'codigo' => 'sin_inventario',
            ], 422);
        }

        $datos = $request->validate([
            'contado' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'motivo' => ['required', Rule::in(MovimientoInventario::MOTIVOS_AJUSTE)],
            'comentario' => ['nullable', 'string', 'max:300'],
        ]);

        $movimiento = Inventario::ajustarA(
            $producto,
            (float) $datos['contado'],
            $datos['motivo'],
            $datos['comentario'] ?? null,
        );

        return response()->json([
            'producto' => $this->comoArreglo($producto->fresh(['categoria', 'suplidor']), true),
            'diferencia' => $movimiento ? (float) $movimiento->cantidad : 0,
        ]);
    }

    /** INV-07: el kardex, con su saldo corrido. */
    public function movimientos(Producto $producto): JsonResponse
    {
        $movimientos = MovimientoInventario::query()
            ->with('usuario:id,nombres,apellidos')
            ->where('producto_id', $producto->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'producto' => ['id' => $producto->ulid, 'nombre' => $producto->nombre],
            'datos' => $movimientos->map(fn (MovimientoInventario $m) => [
                'id' => $m->ulid,
                'fecha' => $m->created_at?->toIso8601String(),
                'tipo' => $m->tipo,
                'cantidad' => (float) $m->cantidad,
                'existencia_resultante' => (float) $m->existencia_resultante,
                'motivo' => $m->motivo,
                'comentario' => $m->comentario,
                'usuario' => $m->usuario?->nombreCompleto(),
            ])->all(),
        ]);
    }

    private function comoArreglo(Producto $p, bool $veCostos): array
    {
        $datos = [
            'id' => $p->ulid,
            'nombre' => $p->nombre,
            'sku' => $p->sku,
            'codigo_barras' => $p->codigo_barras,
            'descripcion' => $p->descripcion,
            'unidad' => $p->unidad,
            'precio' => Precio::aTexto($p->precio_centavos),
            'impuesto' => Precio::tasaATexto($p->impuesto_milesimas),
            'es_servicio' => $p->es_servicio,
            'activo' => $p->activo,
            'existencia' => $p->es_servicio ? null : (float) $p->existencia,
            'existencia_minima' => $p->es_servicio ? null : (float) $p->existencia_minima,
            'estado_existencia' => $p->estadoExistencia(),
            'categoria' => $p->categoria ? [
                'id' => $p->categoria->ulid,
                'nombre' => $p->categoria->nombre,
                'color' => $p->categoria->color,
            ] : null,
            'suplidor' => $p->suplidor ? [
                'id' => $p->suplidor->ulid,
                'nombre' => $p->suplidor->nombre(),
            ] : null,
        ];

        // El costo y el margen no son para todos los roles (matriz del doc 04).
        if ($veCostos) {
            $datos['costo'] = Precio::aTexto($p->costo_centavos);
            $datos['margen'] = $p->margen();
            $datos['vende_bajo_costo'] = $p->vendeBajoCosto();
        }

        return $datos;
    }
}
