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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Emitir, cobrar y anular. Todo lo que toca dinero y existencias pasa por aquí.
 *
 * Una factura tiene dos vidas. Mientras es **borrador** se puede cambiar
 * entera, no tiene número y no ha movido inventario: no existe para nadie más
 * que para quien la escribe. Al **emitirse** saca número de la serie, descuenta
 * la mercancía y se congela: desde ahí solo se cobra o se anula (FAC-09).
 */
final class Facturador
{
    /**
     * Calcula una factura sin guardar nada, para que la pantalla muestre los
     * totales mientras se arma la venta.
     *
     * @param  list<array<string, mixed>>  $renglones  con producto (ulid), cantidad, precio, impuesto, descuento
     */
    public static function calcular(array $renglones, array $descuentoGlobal = [], ?Cliente $cliente = null): array
    {
        $empresa = self::empresaActual();
        $productos = self::productosDe($renglones);
        $exentoElCliente = (bool) $cliente?->exento;
        $tasaPorDefecto = self::tasaEstandar($empresa);

        $paraCalcular = [];

        foreach ($renglones as $renglon) {
            $producto = $productos[$renglon['producto'] ?? ''] ?? null;

            $paraCalcular[] = [
                'cantidad' => (float) ($renglon['cantidad'] ?? 0),
                'precio_centavos' => self::precioDe($renglon, $producto),
                'descuento_tipo' => $renglon['descuento_tipo'] ?? null,
                'descuento_valor' => $renglon['descuento_valor'] ?? null,
                'impuesto_milesimas' => self::tasaDe($renglon, $producto, $tasaPorDefecto),
                // El cliente exento no paga impuesto en ningún renglón (FAC-06).
                'exento' => $exentoElCliente,
            ];
        }

        return Totales::calcular($paraCalcular, $descuentoGlobal, self::desgloseDe($empresa));
    }

    /**
     * Guarda la factura sin emitirla: ni número, ni inventario, ni cobro.
     * Sirve para dejarla a medias y volver mañana (FAC-02).
     *
     * @param  array<string, mixed>  $datos
     */
    public static function guardarBorrador(array $datos, ?Documento $borrador = null): Documento
    {
        return DB::transaction(function () use ($datos, $borrador) {
            if ($borrador) {
                self::exigirBorrador($borrador);
            }

            $renglones = $datos['renglones'] ?? [];
            $cliente = $datos['cliente'] ?? null;
            $calculo = self::calcular($renglones, $datos['descuento'] ?? [], $cliente);

            $documento = self::guardarCabecera(
                $borrador,
                $datos,
                $calculo,
                ['estado' => 'borrador', 'numero' => null, 'folio' => null],
            );

            self::escribirRenglones($documento, $renglones, $calculo, moverInventario: false);

            return $documento->fresh(['renglones', 'pagos']);
        });
    }

    /**
     * Emite: le pone número, descuenta existencias y la deja cobrada en lo que
     * se haya pagado. Todo en una sola transacción.
     *
     * Con `$borrador` convierte ese borrador en factura conservando su
     * identificador, de modo que el enlace que alguien tenía abierto sigue
     * llevando al mismo documento.
     *
     * @param  array<string, mixed>  $datos  renglones, cliente, pagos, fecha...
     */
    public static function emitir(array $datos, bool $permitirSinExistencia = false, ?Documento $borrador = null): Documento
    {
        $renglones = $datos['renglones'] ?? [];

        if ($renglones === []) {
            throw new \DomainException('Una factura sin renglones no se puede emitir.');
        }

        return DB::transaction(function () use ($datos, $renglones, $permitirSinExistencia, $borrador) {
            if ($borrador) {
                self::exigirBorrador($borrador);
            }

            $cliente = $datos['cliente'] ?? null;
            $calculo = self::calcular($renglones, $datos['descuento'] ?? [], $cliente);

            self::exigirExistencia($renglones, $permitirSinExistencia);

            // --- numeración sin huecos (ARQ-08) -------------------------------
            $serie = self::serieDe('factura');
            $numero = self::siguienteNumero($serie);

            $documento = self::guardarCabecera($borrador, $datos, $calculo, [
                'serie_id' => $serie->id,
                'estado' => 'emitida',
                'numero' => $numero,
                'folio' => $serie->folio($numero),
                'emitida_en' => now(),
            ]);

            self::escribirRenglones($documento, $renglones, $calculo, moverInventario: true);

            foreach ($datos['pagos'] ?? [] as $pago) {
                self::registrarPago($documento, $pago);
            }

            return $documento->fresh(['renglones', 'pagos']);
        });
    }

    /**
     * Descarta un borrador. Se borra de verdad, porque nunca existió para
     * nadie: no tuvo número ni movió mercancía. Lo emitido se anula, no se
     * borra, y de eso se encarga la base de datos.
     */
    public static function descartarBorrador(Documento $borrador): void
    {
        self::exigirBorrador($borrador);

        DB::transaction(function () use ($borrador) {
            $borrador->renglones()->delete();
            $borrador->delete();
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

    // --- cabecera y renglones -----------------------------------------------

    /**
     * Los campos que comparten el borrador y la factura emitida. `$propios` es
     * lo que distingue a cada una: número, estado, folio.
     */
    private static function guardarCabecera(?Documento $documento, array $datos, array $calculo, array $propios): Documento
    {
        /** @var ?Cliente $cliente */
        $cliente = $datos['cliente'] ?? null;
        $terminos = $datos['terminos_pago'] ?? $cliente?->terminos_pago;
        $fecha = self::fecha($datos['fecha'] ?? null);

        $campos = [
            'cliente_id' => $cliente?->id,
            'tipo' => 'factura',
            'fecha' => $fecha,
            // El nombre se copia: si luego renombran al cliente, la factura
            // tiene que seguir diciendo lo que decía.
            'cliente_nombre' => $cliente?->nombre,
            'cliente_exento' => (bool) $cliente?->exento,
            'vendedor' => self::texto($datos['vendedor'] ?? null),
            'referencia' => self::texto($datos['referencia'] ?? null),
            'descuento_tipo' => $datos['descuento']['tipo'] ?? null,
            'descuento_valor' => $datos['descuento']['valor'] ?? null,
            'subtotal_centavos' => $calculo['subtotal'],
            'descuento_centavos' => $calculo['descuento_renglones'] + $calculo['descuento_global'],
            'base_centavos' => $calculo['base'],
            'impuesto_centavos' => $calculo['impuesto'],
            'total_centavos' => $calculo['total'],
            'impuesto_desglose' => $calculo['desglose'],
            'terminos_pago' => $terminos,
            'vence_el' => self::vencimiento($datos['vence_el'] ?? null, $terminos, $fecha),
            'notas' => self::texto($datos['notas'] ?? null),
        ];

        $campos = array_merge($campos, $propios);

        if ($documento) {
            $documento->update($campos);

            return $documento;
        }

        return Documento::create(array_merge($campos, [
            'pagado_centavos' => 0,
            'usuario_id' => Auth::guard('empresa')->id(),
        ]));
    }

    /**
     * Reescribe los renglones. Al guardar un borrador se borran y se vuelven a
     * escribir: es más simple y más seguro que casarlos uno a uno, y un
     * borrador no tiene historia que preservar.
     */
    private static function escribirRenglones(Documento $documento, array $renglones, array $calculo, bool $moverInventario): void
    {
        $documento->renglones()->delete();

        $productos = self::productosDe($renglones);

        foreach ($renglones as $i => $renglon) {
            $producto = $productos[$renglon['producto'] ?? ''] ?? null;
            $calculado = $calculo['renglones'][$i];

            DocumentoRenglon::create([
                'documento_id' => $documento->id,
                'producto_id' => $producto?->id,
                'orden' => $i,
                // Se copia: la factura no cambia si el producto se renombra.
                'descripcion' => self::texto($renglon['descripcion'] ?? null) ?? $producto?->nombre ?? 'Sin descripción',
                'detalle' => self::texto($renglon['detalle'] ?? null),
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

            if ($moverInventario && $producto && ! $producto->es_servicio && $calculado['cantidad'] > 0) {
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
    }

    /** Se revisa antes de tocar nada: o alcanza todo, o no se vende nada (FAC-08). */
    private static function exigirExistencia(array $renglones, bool $permitir): void
    {
        if ($permitir) {
            return;
        }

        $productos = self::productosDe($renglones);
        $faltantes = [];

        foreach ($renglones as $renglon) {
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

        if ($faltantes !== []) {
            throw new SinExistencia($faltantes);
        }
    }

    private static function exigirBorrador(Documento $documento): void
    {
        if ($documento->estado !== 'borrador') {
            throw new \DomainException('Una factura ya emitida no se modifica: se anula y se hace otra.');
        }
    }

    // --- apoyo ---------------------------------------------------------------

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

    /**
     * La tasa del renglón. Manda la que se escribió en la hoja; si no, la del
     * producto; y si el renglón es texto libre —un servicio escrito a mano—,
     * la tasa estándar de la empresa, que es lo que de verdad se cobra. Antes
     * un renglón sin producto salía sin impuesto, y eso es una factura mal
     * hecha.
     */
    private static function tasaDe(array $renglon, ?Producto $producto, int $porDefecto): int
    {
        if (isset($renglon['impuesto']) && $renglon['impuesto'] !== '' && $renglon['impuesto'] !== null) {
            return max(0, Precio::tasaAMilesimas($renglon['impuesto']) ?? 0);
        }

        return $producto?->impuesto_milesimas ?? $porDefecto;
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

    /** La suma de los componentes: 10.5% estatal + 1% municipal = 11.5%. */
    private static function tasaEstandar(?Empresa $empresa): int
    {
        return (int) array_sum(array_column(self::desgloseDe($empresa), 'milesimas'));
    }

    private static function fecha(?string $fecha): string
    {
        return $fecha ? Carbon::parse($fecha)->toDateString() : now()->toDateString();
    }

    /**
     * El vencimiento: el que se escribió en la hoja, o el que sale de los
     * términos contados desde la fecha del documento ("30 dias", FAC-15).
     */
    private static function vencimiento(?string $explicito, ?string $terminos, string $fecha): ?string
    {
        if ($explicito) {
            return Carbon::parse($explicito)->toDateString();
        }

        if ($terminos && preg_match('/(\d+)\s*d/i', $terminos, $coincidencias)) {
            return Carbon::parse($fecha)->addDays((int) $coincidencias[1])->toDateString();
        }

        return null;
    }

    /** Un campo vacío es ausencia, no una cadena en blanco guardada. */
    private static function texto(?string $valor): ?string
    {
        $limpio = trim((string) $valor);

        return $limpio === '' ? null : $limpio;
    }
}
