<?php

namespace App\Http\V1\Controllers;

use App\Domain\Precio;
use App\Models\Cliente;
use App\Models\Documento;
use App\Models\DocumentoRenglon;
use App\Models\Pago;
use App\Soporte\Facturador;
use App\Soporte\Permisos;
use App\Soporte\SinExistencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class FacturaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in([...Documento::ESTADOS, 'todas', 'pendientes'])],
            'cliente' => ['nullable', 'string', 'size:26'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'orden' => ['nullable', Rule::in(Documento::ORDENABLES)],
            'direccion' => ['nullable', Rule::in(['asc', 'desc'])],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $estado = $filtros['estado'] ?? 'todas';

        $consulta = Documento::query()
            ->where('tipo', 'factura')
            ->buscar($filtros['buscar'] ?? null)
            ->when($estado === 'pendientes', fn ($q) => $q->whereIn('estado', ['emitida', 'pagada_parcial', 'vencida']))
            ->when(! in_array($estado, ['todas', 'pendientes'], true), fn ($q) => $q->where('estado', $estado))
            ->when($filtros['cliente'] ?? null, fn ($q, $ulid) => $q->whereHas('cliente', fn ($c) => $c->where('ulid', $ulid)))
            ->when($filtros['desde'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn ($q, $h) => $q->whereDate('fecha', '<=', $h));

        // Totales de la vista tal como está filtrada (FAC-13). Los borradores
        // no suman: todavía no son una venta, y contarlos como facturado
        // inflaría la cifra que la dueña mira para saber cómo va el día.
        $resumen = (clone $consulta)
            ->where('estado', '!=', 'borrador')
            ->selectRaw('count(*) as cantidad, coalesce(sum(total_centavos), 0) as total, coalesce(sum(pagado_centavos), 0) as pagado')
            ->first();

        $borradores = (clone $consulta)->where('estado', 'borrador')->count();

        // El orden base es el identificador interno, no el número: un borrador
        // todavía no lo tiene, y paginar por una columna con nulos se salta
        // filas sin avisar. Quien quiera otro orden lo pide explícito.
        $direccion = $filtros['direccion'] ?? 'desc';

        $pagina = $consulta
            ->when($filtros['orden'] ?? null, fn ($q, $orden) => $q->orderBy($orden, $direccion))
            ->orderBy('id', $direccion)
            ->cursorPaginate($filtros['por_pagina'] ?? 25);

        $membresia = $request->attributes->get('membresia');

        return response()->json([
            'datos' => collect($pagina->items())->map(fn (Documento $d) => $this->comoResumen($d))->all(),
            'siguiente' => $pagina->nextCursor()?->encode(),
            'anterior' => $pagina->previousCursor()?->encode(),
            'resumen' => [
                'cantidad' => (int) ($resumen->cantidad ?? 0),
                'total' => Precio::aTexto((int) ($resumen->total ?? 0)),
                'pagado' => Precio::aTexto((int) ($resumen->pagado ?? 0)),
                'por_cobrar' => Precio::aTexto((int) ($resumen->total ?? 0) - (int) ($resumen->pagado ?? 0)),
                'borradores' => $borradores,
            ],
            'metodos_pago' => Pago::METODOS,
            'permisos' => [
                'facturar' => Permisos::permite($membresia, Permisos::FACTURAR),
                'anular' => Permisos::permite($membresia, Permisos::FACTURAS_ANULAR),
            ],
        ]);
    }

    public function ver(Documento $documento): JsonResponse
    {
        return response()->json($this->comoDetalle($documento->load(['renglones.producto', 'pagos', 'usuario', 'cliente'])));
    }

    /** Los totales mientras se arma la venta, sin guardar nada. */
    public function calcular(Request $request): JsonResponse
    {
        $datos = $this->validarVenta($request, conPagos: false, exigirRenglones: false);

        $calculo = Facturador::calcular(
            $datos['renglones'] ?? [],
            $this->descuentoGlobal($datos),
            $this->clienteDe($datos),
        );

        return response()->json($this->comoCalculo($calculo));
    }

    /** Guarda sin emitir: la factura queda a medias y se puede retomar (FAC-02). */
    public function guardarBorrador(Request $request): JsonResponse
    {
        $datos = $this->validarVenta($request, conPagos: false, exigirRenglones: false);

        $documento = Facturador::guardarBorrador($this->comoOrden($datos));

        return response()->json($this->comoDetalle($documento->load(['renglones.producto', 'pagos', 'cliente'])), 201);
    }

    /** Reescribe un borrador. Lo ya emitido no se toca: se anula (FAC-09). */
    public function actualizarBorrador(Request $request, Documento $documento): JsonResponse
    {
        $datos = $this->validarVenta($request, conPagos: false, exigirRenglones: false);

        try {
            $guardado = Facturador::guardarBorrador($this->comoOrden($datos), $documento);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'codigo' => 'no_es_borrador'], 422);
        }

        return response()->json($this->comoDetalle($guardado->load(['renglones.producto', 'pagos', 'cliente'])));
    }

    public function descartarBorrador(Documento $documento): JsonResponse
    {
        try {
            Facturador::descartarBorrador($documento);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'codigo' => 'no_es_borrador'], 422);
        }

        return response()->json(['mensaje' => 'Borrador descartado.']);
    }

    /** Emite una factura nueva, sin pasar por borrador: la venta de mostrador. */
    public function emitir(Request $request): JsonResponse
    {
        return $this->emitirDocumento($request, null);
    }

    /** Emite un borrador ya guardado, conservando su identificador. */
    public function emitirBorrador(Request $request, Documento $documento): JsonResponse
    {
        return $this->emitirDocumento($request, $documento);
    }

    public function cobrar(Request $request, Documento $documento): JsonResponse
    {
        $datos = $request->validate([
            'metodo' => ['required', Rule::in(Pago::METODOS)],
            'monto' => ['nullable', 'string', 'max:20'],
            'recibido' => ['nullable', 'string', 'max:20'],
            'referencia' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            Facturador::registrarPago($documento, $datos);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'codigo' => 'no_se_puede_cobrar'], 422);
        }

        return response()->json($this->comoDetalle($documento->fresh(['renglones.producto', 'pagos', 'cliente'])));
    }

    public function anular(Request $request, Documento $documento): JsonResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:4', 'max:200'],
        ], [
            'motivo.required' => 'Para anular una factura hay que decir por qué.',
        ]);

        try {
            $anulada = Facturador::anular($documento, $datos['motivo']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'codigo' => 'no_se_puede_anular'], 422);
        }

        return response()->json($this->comoDetalle($anulada->fresh(['renglones.producto', 'pagos', 'cliente'])));
    }

    // --- apoyo --------------------------------------------------------------

    private function emitirDocumento(Request $request, ?Documento $borrador): JsonResponse
    {
        $datos = $this->validarVenta($request, conPagos: true, exigirRenglones: true);

        try {
            $documento = Facturador::emitir(
                $this->comoOrden($datos),
                (bool) ($datos['permitir_sin_existencia'] ?? false),
                $borrador,
            );
        } catch (SinExistencia $e) {
            // No es un error técnico: es una pregunta para quien está en la caja.
            return response()->json([
                'message' => 'No hay existencia suficiente para vender.',
                'codigo' => 'sin_existencia',
                'faltantes' => $e->faltantes,
            ], 409);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'codigo' => 'no_se_puede_emitir'], 422);
        }

        return response()->json($this->comoDetalle($documento->load(['renglones.producto', 'pagos', 'cliente'])), 201);
    }

    private function validarVenta(Request $request, bool $conPagos, bool $exigirRenglones): array
    {
        $reglas = [
            'cliente' => ['nullable', 'string', 'size:26'],
            'fecha' => ['nullable', 'date'],
            'vence_el' => ['nullable', 'date', 'after_or_equal:fecha'],
            'vendedor' => ['nullable', 'string', 'max:120'],
            'referencia' => ['nullable', 'string', 'max:60'],
            'renglones' => [$exigirRenglones ? 'required' : 'nullable', 'array', 'max:200'],
            'renglones.*.producto' => ['nullable', 'string', 'size:26'],
            'renglones.*.descripcion' => ['nullable', 'string', 'max:200'],
            'renglones.*.detalle' => ['nullable', 'string', 'max:500'],
            'renglones.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'renglones.*.precio' => ['nullable', 'string', 'max:20'],
            'renglones.*.impuesto' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'renglones.*.descuento_tipo' => ['nullable', Rule::in(['monto', 'porcentaje'])],
            'renglones.*.descuento_valor' => ['nullable', 'string', 'max:20'],
            'descuento_tipo' => ['nullable', Rule::in(['monto', 'porcentaje'])],
            'descuento_valor' => ['nullable', 'string', 'max:20'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'terminos_pago' => ['nullable', 'string', 'max:60'],
        ];

        if ($exigirRenglones) {
            $reglas['renglones'][] = 'min:1';
        }

        if ($conPagos) {
            $reglas += [
                'pagos' => ['nullable', 'array', 'max:10'],
                'pagos.*.metodo' => ['required', Rule::in(Pago::METODOS)],
                'pagos.*.monto' => ['nullable', 'string', 'max:20'],
                'pagos.*.recibido' => ['nullable', 'string', 'max:20'],
                'pagos.*.referencia' => ['nullable', 'string', 'max:60'],
                'permitir_sin_existencia' => ['boolean'],
            ];
        }

        return $request->validate($reglas, [
            'renglones.required' => 'Una factura sin renglones no se puede emitir.',
            'renglones.*.cantidad.gt' => 'La cantidad tiene que ser mayor que cero.',
            'vence_el.after_or_equal' => 'El vencimiento no puede ser anterior a la fecha de la factura.',
        ]);
    }

    /** Lo que llega por HTTP, con el cliente ya resuelto, como lo espera el Facturador. */
    private function comoOrden(array $datos): array
    {
        return [
            'renglones' => $datos['renglones'] ?? [],
            'cliente' => $this->clienteDe($datos),
            'descuento' => $this->descuentoGlobal($datos),
            'pagos' => $datos['pagos'] ?? [],
            'fecha' => $datos['fecha'] ?? null,
            'vence_el' => $datos['vence_el'] ?? null,
            'vendedor' => $datos['vendedor'] ?? null,
            'referencia' => $datos['referencia'] ?? null,
            'notas' => $datos['notas'] ?? null,
            'terminos_pago' => $datos['terminos_pago'] ?? null,
        ];
    }

    private function clienteDe(array $datos): ?Cliente
    {
        if (empty($datos['cliente'])) {
            return null;
        }

        // Si no es de esta empresa, la consulta no lo encuentra (ARQ-04).
        return Cliente::query()->where('ulid', $datos['cliente'])->first();
    }

    private function descuentoGlobal(array $datos): array
    {
        return [
            'tipo' => $datos['descuento_tipo'] ?? null,
            'valor' => $datos['descuento_valor'] ?? null,
        ];
    }

    private function comoCalculo(array $c): array
    {
        return [
            'subtotal' => Precio::aTexto($c['subtotal']),
            'descuento' => Precio::aTexto($c['descuento_renglones'] + $c['descuento_global']),
            'base' => Precio::aTexto($c['base']),
            'impuesto' => Precio::aTexto($c['impuesto']),
            'total' => Precio::aTexto($c['total']),
            'desglose' => array_map(fn ($d) => [
                'nombre' => $d['nombre'],
                'monto' => Precio::aTexto($d['monto']),
            ], $c['desglose']),
            'renglones' => array_map(fn ($r) => [
                'bruto' => Precio::aTexto($r['bruto_centavos']),
                'descuento' => Precio::aTexto($r['descuento_centavos'] + $r['descuento_global_centavos']),
                'base' => Precio::aTexto($r['base_centavos']),
                'impuesto' => Precio::aTexto($r['impuesto_centavos']),
                'tasa' => Precio::tasaATexto($r['impuesto_milesimas']),
                'total' => Precio::aTexto($r['total_centavos']),
            ], $c['renglones']),
        ];
    }

    private function comoResumen(Documento $d): array
    {
        return [
            'id' => $d->ulid,
            'folio' => $d->folio,
            'estado' => $d->estado,
            'cliente' => $d->cliente_nombre,
            'fecha' => $d->fecha?->toDateString(),
            'emitida_en' => $d->emitida_en?->toIso8601String(),
            'vence_el' => $d->vence_el?->toDateString(),
            'total' => Precio::aTexto($d->total_centavos),
            'pagado' => Precio::aTexto($d->pagado_centavos),
            'saldo' => Precio::aTexto($d->saldoCentavos()),
        ];
    }

    private function comoDetalle(Documento $d): array
    {
        return $this->comoResumen($d) + [
            'subtotal' => Precio::aTexto($d->subtotal_centavos),
            'descuento' => Precio::aTexto($d->descuento_centavos),
            'descuento_tipo' => $d->descuento_tipo,
            'descuento_valor' => $d->descuento_valor,
            'base' => Precio::aTexto($d->base_centavos),
            'impuesto' => Precio::aTexto($d->impuesto_centavos),
            'desglose' => array_map(fn ($x) => [
                'nombre' => $x['nombre'],
                'monto' => Precio::aTexto($x['monto']),
            ], $d->impuesto_desglose ?? []),
            'cliente_id' => $d->cliente?->ulid,
            'cliente_exento' => $d->cliente_exento,
            'vendedor' => $d->vendedor,
            'referencia' => $d->referencia,
            'terminos_pago' => $d->terminos_pago,
            'notas' => $d->notas,
            'motivo_anulacion' => $d->motivo_anulacion,
            'emitida_por' => $d->usuario?->nombreCompleto(),
            'editable' => $d->estado === 'borrador',
            'renglones' => $d->renglones->map(fn (DocumentoRenglon $r) => [
                'id' => $r->ulid,
                // El ULID del producto es lo que necesita la hoja para volver a
                // seleccionarlo al reabrir un borrador.
                'producto' => $r->producto?->ulid,
                'descripcion' => $r->descripcion,
                'detalle' => $r->detalle,
                'sku' => $r->sku,
                'cantidad' => (float) $r->cantidad,
                'unidad' => $r->unidad,
                'precio' => Precio::aTexto($r->precio_centavos),
                'tasa' => Precio::tasaATexto($r->impuesto_milesimas),
                'descuento' => Precio::aTexto($r->descuento_centavos),
                'descuento_tipo' => $r->descuento_tipo,
                'descuento_valor' => $r->descuento_valor,
                'impuesto' => Precio::aTexto($r->impuesto_centavos),
                'total' => Precio::aTexto($r->total_centavos),
            ])->all(),
            'pagos' => $d->pagos->map(fn (Pago $p) => [
                'id' => $p->ulid,
                'metodo' => $p->metodo,
                'monto' => Precio::aTexto($p->monto_centavos),
                'cambio' => Precio::aTexto($p->cambioCentavos()),
                'referencia' => $p->referencia,
                'fecha' => $p->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
