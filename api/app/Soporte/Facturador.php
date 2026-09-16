<?php

namespace App\Soporte;

use App\Domain\Precio;
use App\Domain\Totales;
use App\Models\Cliente;
use App\Models\Documento;
use App\Models\DocumentoRenglon;
use App\Models\Empresa;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\SerieDocumento;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Emitir, cobrar y anular. Todo lo que toca dinero y existencias pasa por aquí.
 */
final class Facturador
{
    /**
     * Calcula una factura sin guardar nada, para que la pantalla muestre los
     * totales mientras se arma la venta.
     *
     * @param  list<array<string, mixed>>  $renglones  con producto (ulid), cantidad, precio, descuento
     */
    public static function calcular(array $renglones, array $descuentoGlobal = [], ?Cliente $cliente = null): array
    {
        $productos = self::productosDe($renglones);
        $exentoElCliente = (bool) $cliente?->exento;

        $paraCalcular = [];

        foreach ($renglones as $renglon) {
            $producto = $productos[$renglon['producto'] ?? ''] ?? null;

            $paraCalcular[] = [
                'cantidad' => (float) ($renglon['cantidad'] ?? 0),
                'precio_centavos' => self::precioDe($renglon, $producto),
                'descuento_tipo' => $renglon['descuento_tipo'] ?? null,
                'descuento_valor' => $renglon['descuento_valor'] ?? null,
                'impuesto_milesimas' => $producto?->impuesto_milesimas ?? 0,
                // El cliente exento no paga impuesto en ningún renglón (FAC-06).
                'exento' => $exentoElCliente,
            ];
        }

        return Totales::calcular($paraCalcular, $descuentoGlobal, self::desgloseDe(self::empresaActual()));
    }

    /**
     * Emite la factura: le pone número, descuenta existencias y la deja cobrada
     * en lo que se haya pagado. Todo en una sola transacción.
     *
     * @param  list<array<string, mixed>>  $renglones
     * @param  list<array{metodo: string, monto: string|int, recibido?: string|int|null, referencia?: ?string}>  $pagos
     */
    public static function emitir(
        array $renglones,
        array $pagos = [],
        ?Cliente $cliente = null,
        array $descuentoGlobal = [],
        ?string $notas = null,
        ?string $terminosPago = null,
        bool $permitirSinExistencia = false,
    ): Documento {
        if ($renglones === []) {
            throw new \DomainException('Una factura sin renglones no se puede emitir.');
        }

        return DB::transaction(function () use ($renglones, $pagos, $cliente, $descuentoGlobal, $notas, $terminosPago, $permitirSinExistencia) {
            $empresa = self::empresaActual();
            $productos = self::productosDe($renglones);
            $calculo = self::calcular($renglones, $descuentoGlobal, $cliente);

            // --- existencias: se revisa antes de tocar nada (FAC-08) ----------
            $faltantes = [];

            foreach ($renglones as $i => $renglon) {
                $producto = $productos[$renglon['producto'] ?? ''] ?? null;

                if (! $producto || $producto->es_servicio) {
                    continue;
                }

                $cantidad = (float) ($renglon['cantidad'] ?? 0);

                if ((float) $producto->existencia < $cantidad) {
                    $faltantes[] = [
                        'producto' => $producto->nombre,
                        'pedido' => $cantidad,
                        'disponible' => (float) $producto->existencia,
                    ];
                }
            }

            if ($faltantes !== [] && ! $permitirSinExistencia) {
                throw new SinExistencia($faltantes);
            }

            // --- numeración sin huecos (ARQ-08) -------------------------------
            $serie = self::serieDe('factura');
            $numero = self::siguienteNumero($serie);

            $documento = Documento::create([
                'serie_id' => $serie->id,
                'cliente_id' => $cliente?->id,
                'tipo' => 'factura',
                'estado' => 'emitida',
                'numero' => $numero,
                'folio' => $serie->folio($numero),
                'cliente_nombre' => $cliente?->nombre,
                'cliente_exento' => (bool) $cliente?->exento,
                'descuento_tipo' => $descuentoGlobal['tipo'] ?? null,
                'descuento_valor' => $descuentoGlobal['valor'] ?? null,
                'subtotal_centavos' => $calculo['subtotal'],
                'descuento_centavos' => $calculo['descuento_renglones'] + $calculo['descuento_global'],
                'base_centavos' => $calculo['base'],
                'impuesto_centavos' => $calculo['impuesto'],
                'total_centavos' => $calculo['total'],
                'pagado_centavos' => 0,
                'impuesto_desglose' => $calculo['desglose'],
                'terminos_pago' => $terminosPago ?? $cliente?->terminos_pago,
                'vence_el' => self::vencimiento($terminosPago ?? $cliente?->terminos_pago),
                'notas' => $notas,
                'usuario_id' => Auth::guard('empresa')->id(),
                'emitida_en' => now(),
            ]);

            foreach ($renglones as $i => $renglon) {
                $producto = $productos[$renglon['producto'] ?? ''] ?? null;
                $calculado = $calculo['renglones'][$i];

                DocumentoRenglon::create([
                    'documento_id' => $documento->id,
                    'producto_id' => $producto?->id,
                    'orden' => $i,
                    // Se copia: la factura no cambia si el producto se renombra.
                    'descripcion' => $renglon['descripcion'] ?? $producto?->nombre ?? 'Sin descripción',
                    'sku' => $producto?->sku,
                    'unidad' => $producto?->unidad ?? 'unidad',
                    'es_servicio' => (bool) $producto?->es_servicio,
                    'cantidad' => $calculado['cantidad'],
                    'precio_centavos' => $calculado['precio_centavos'],
                    'descuento_tipo' => $renglon['descuento_tipo'] ?? null,
                    'descuento_valor' => $renglon['descuento_valor'] ?? null,
                    'impuesto_milesimas' => $calculado['impuesto_milesimas'],
                    'exento' => $calculado['exento'],
                    'bruto_centavos' => $calculado['bruto_centavos'],
                    'descuento_centavos' => $calculado['descuento_centavos'] + $calculado['descuento_global_centavos'],
                    'base_centavos' => $calculado['base_centavos'],
                    'impuesto_centavos' => $calculado['impuesto_centavos'],
                    'total_centavos' => $calculado['total_centavos'],
                ]);

                // --- se descuenta el inventario en la misma transacción -------
                if ($producto && ! $producto->es_servicio && $calculado['cantidad'] > 0) {
                    Inventario::registrar(
                        $producto,
                        'venta',
                        -$calculado['cantidad'],
                        null,
                        null,
                        $producto->costo_centavos ?: null,
                        'documento',
                        $documento->id,
                    );
                }
            }

            foreach ($pagos as $pago) {
                self::registrarPago($documento, $pago);
            }

            return $documento->fresh(['renglones', 'pagos']);
        });
    }

    /** Registra un cobro y recalcula el estado del documento (FAC-02, FAC-07). */
    public static function registrarPago(Documento $documento, array $pago): Pago
    {
        return DB::transaction(function () use ($documento, $pago) {
            $fresco = Documento::query()->lockForUpdate()->findOrFail($documento->id);

            if ($fresco->estado === 'anulada') {
                throw new \DomainException('Una factura anulada no se puede cobrar.');
            }

            if ($fresco->estado === 'borrador') {
                throw new \DomainException('Hay que emitir la factura antes de cobrarla.');
            }

            // Sin monto se cobra el saldo entero. Así la caja dice "paga todo
            // en efectivo" y el importe lo pone el servidor, que es el que sabe
            // cuánto es: el del navegador puede venir de un cálculo a medio
            // refrescar.
            $monto = isset($pago['monto']) && $pago['monto'] !== null && $pago['monto'] !== ''
                ? Precio::monto($pago['monto'])
                : $fresco->saldoCentavos();

            if ($monto <= 0) {
                throw new \DomainException('El monto del pago tiene que ser mayor que cero.');
            }

            if ($monto > $fresco->saldoCentavos()) {
                throw new \DomainException('El pago no puede ser mayor que el saldo pendiente.');
            }

            $registro = Pago::create([
                'documento_id' => $fresco->id,
                'metodo' => $pago['metodo'],
                'monto_centavos' => $monto,
                'recibido_centavos' => isset($pago['recibido']) ? Precio::monto($pago['recibido']) : null,
                'referencia' => $pago['referencia'] ?? null,
                'usuario_id' => Auth::guard('empresa')->id(),
            ]);

            $fresco->pagado_centavos += $monto;
            $fresco->estado = $fresco->estadoSegunPagos();
            $fresco->save();

            $documento->setAttribute('pagado_centavos', $fresco->pagado_centavos);
            $documento->setAttribute('estado', $fresco->estado);

            return $registro;
        });
    }

    /**
     * Anula: no borra, revierte el inventario y deja el número ocupado (FAC-09).
     */
    public static function anular(Documento $documento, string $motivo): Documento
    {
        return DB::transaction(function () use ($documento, $motivo) {
            $fresco = Documento::query()->with('renglones')->lockForUpdate()->findOrFail($documento->id);

            if ($fresco->estado === 'anulada') {
                throw new \DomainException('Esta factura ya está anulada.');
            }

            if ($fresco->estado === 'borrador') {
                throw new \DomainException('Un borrador no se anula: se descarta.');
            }

            foreach ($fresco->renglones as $renglon) {
                if ($renglon->es_servicio || ! $renglon->producto_id) {
                    continue;
                }

                $producto = Producto::query()->find($renglon->producto_id);

                if ($producto) {
                    Inventario::registrar(
                        $producto,
                        'devolucion',
                        (float) $renglon->cantidad,
                        'anulación',
                        'Factura '.$fresco->folio,
                        null,
                        'documento',
                        $fresco->id,
                    );
                }
            }

            $fresco->update([
                'estado' => 'anulada',
                'anulada_en' => now(),
                'motivo_anulacion' => $motivo,
            ]);

            return $fresco;
        });
    }

    /**
     * El siguiente número de la serie, con la fila bloqueada: dos cajeros a la
     * vez no pueden sacar el mismo (ARQ-08).
     */
    private static function siguienteNumero(SerieDocumento $serie): int
    {
        $bloqueada = SerieDocumento::query()->lockForUpdate()->findOrFail($serie->id);
        $numero = $bloqueada->proximo_numero;

        $bloqueada->update(['proximo_numero' => $numero + 1]);
        $serie->setAttribute('proximo_numero', $numero + 1);

        return $numero;
    }

    public static function serieDe(string $tipo): SerieDocumento
    {
        $serie = SerieDocumento::query()->where('tipo', $tipo)->orderByDesc('predeterminada')->first();

        if ($serie) {
            return $serie;
        }

        return SerieDocumento::create([
            'tipo' => $tipo,
            'nombre' => 'Principal',
            'prefijo' => $tipo === 'factura' ? 'F' : mb_strtoupper(mb_substr($tipo, 0, 3)),
            'proximo_numero' => 1,
            'predeterminada' => true,
        ]);
    }

    /** @return array<string, Producto> por ULID */
    private static function productosDe(array $renglones): array
    {
        $ulids = array_values(array_filter(array_map(fn ($r) => $r['producto'] ?? null, $renglones)));

        if ($ulids === []) {
            return [];
        }

        return Producto::query()->whereIn('ulid', $ulids)->get()->keyBy('ulid')->all();
    }

    private static function precioDe(array $renglon, ?Producto $producto): int
    {
        if (isset($renglon['precio']) && $renglon['precio'] !== '' && $renglon['precio'] !== null) {
            return Precio::monto($renglon['precio']);
        }

        return $producto?->precio_centavos ?? 0;
    }

    private static function empresaActual(): ?Empresa
    {
        $id = ContextoRls::empresaActual();

        return $id ? Empresa::query()->find($id) : null;
    }

    /** @return list<array{nombre: string, milesimas: int}> */
    private static function desgloseDe(?Empresa $empresa): array
    {
        return $empresa?->impuesto_desglose ?? [];
    }

    /** "30 dias" se convierte en una fecha concreta (FAC-15). */
    private static function vencimiento(?string $terminos): ?string
    {
        if (! $terminos) {
            return null;
        }

        if (preg_match('/(\d+)\s*d/i', $terminos, $coincidencias)) {
            return now()->addDays((int) $coincidencias[1])->toDateString();
        }

        return null;
    }
}
