<?php

namespace App\Http\V1\Controllers;

use App\Domain\Cuadre;
use App\Domain\Precio;
use App\Models\GastoCuadre;
use App\Models\HojaCuadre;
use App\Soporte\Cuadrador;
use App\Models\Empresa;
use App\Soporte\ContextoRls;
use App\Soporte\Pdf;
use App\Soporte\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * La hoja de cuadre: el cierre de caja por turno (CAJ-11 a CAJ-14).
 */
class CuadreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'turno' => ['nullable', Rule::in(HojaCuadre::TURNOS)],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        $membresia = $request->attributes->get('membresia');
        $todas = Permisos::permite($membresia, Permisos::CAJA_VER_TODAS);

        $consulta = HojaCuadre::query()
            ->with(['gastos', 'usuario'])
            // Quien no puede ver las de otros solo ve las suyas (documento 04).
            ->when(! $todas, fn ($q) => $q->where('usuario_id', $request->user()->id))
            ->when($filtros['desde'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn ($q, $h) => $q->whereDate('fecha', '<=', $h))
            ->when($filtros['turno'] ?? null, fn ($q, $t) => $q->where('turno', $t));

        $pagina = $consulta
            ->orderByDesc('fecha')
            ->orderByDesc('turno')
            ->orderByDesc('id')
            ->cursorPaginate($filtros['por_pagina'] ?? 31);

        // El pie del listado: lo gastado y lo depositado en el rango. Se suma
        // en PHP porque el depósito sale de la fórmula, no de una columna.
        $hojas = collect($pagina->items());
        $gastos = $hojas->sum(fn (HojaCuadre $h) => $h->totales()['gastos']);
        $deposito = $hojas->sum(fn (HojaCuadre $h) => $h->totales()['a_depositar']);
        $ventas = $hojas->sum(fn (HojaCuadre $h) => $h->totales()['total_ventas']);

        return response()->json([
            'datos' => $hojas->map(fn (HojaCuadre $h) => $this->comoResumen($h))->all(),
            'siguiente' => $pagina->nextCursor()?->encode(),
            'anterior' => $pagina->previousCursor()?->encode(),
            'resumen' => [
                'hojas' => $hojas->count(),
                'gastos' => Precio::aTexto((int) $gastos),
                'a_depositar' => Precio::aTexto((int) $deposito),
                'ventas' => Precio::aTexto((int) $ventas),
            ],
            'turnos' => HojaCuadre::TURNOS,
            // Para que una hoja nueva se abra en el turno que se acaba de
            // trabajar, contado en hora de la empresa.
            'hoy' => Cuadrador::hoy(),
            'turno_actual' => Cuadrador::turnoActual(),
            'permisos' => [
                'cuadrar' => Permisos::permite($membresia, Permisos::CAJA_CUADRAR),
                'ver_todas' => $todas,
            ],
        ]);
    }

    public function ver(Request $request, HojaCuadre $hoja): JsonResponse
    {
        $this->exigirPropia($request, $hoja);

        return response()->json($this->comoDetalle($hoja->load(['gastos', 'usuario'])));
    }

    /** Lo que el sistema facturó en un turno, para compararlo con lo escrito. */
    public function facturado(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date'],
            'turno' => ['required', Rule::in(HojaCuadre::TURNOS)],
        ]);

        [$desde, $hasta] = Cuadrador::ventana($datos['fecha'], $datos['turno']);
        $facturado = Cuadrador::loFacturado($datos['fecha'], $datos['turno']);

        return response()->json([
            // Las horas ya formateadas en la zona de la empresa: si se mandan
            // en crudo, el navegador de quien mira las pinta en su propia zona
            // y el turno aparece a deshora.
            'desde' => $desde->format('H:i'),
            'hasta' => $hasta->format('H:i'),
            'facturas' => $facturado['facturas'],
            'ventas' => Precio::aTexto($facturado['ventas']),
            'tarjeta' => Precio::aTexto($facturado['tarjeta']),
            'ath_movil' => Precio::aTexto($facturado['ath_movil']),
            'efectivo' => Precio::aTexto($facturado['efectivo']),
        ]);
    }

    /** La hoja en papel, para archivar y para llevar al banco (CAJ-07). */
    public function pdf(Request $request, HojaCuadre $hoja)
    {
        $this->exigirPropia($request, $hoja);
        $hoja->load(['gastos', 'usuario']);

        $facturado = Cuadrador::loFacturado($hoja->fecha->toDateString(), $hoja->turno);

        $papel = Pdf::de(view('pdf.cuadre', [
            'hoja' => $hoja,
            'empresa' => Empresa::query()->findOrFail(ContextoRls::empresaActual()),
            't' => $hoja->totales(),
            'facturado' => $facturado,
            'comparacion' => Cuadre::comparar(
                [
                    'ventas' => $hoja->ventas_lectura_centavos,
                    'tarjeta' => $hoja->tarjeta_centavos,
                    'ath_movil' => $hoja->ath_movil_centavos,
                ],
                ['ventas' => $facturado['ventas'], 'tarjeta' => $facturado['tarjeta'], 'ath_movil' => $facturado['ath_movil']],
            ),
            // La plantilla no sabe de centavos: se le pasa cómo escribirlos.
            'n' => fn (int $centavos) => Precio::aTexto($centavos),
            'impresa' => Cuadrador::hoy(),
        ])->render());

        $nombre = Pdf::nombre('cuadre', $hoja->fecha->toDateString(), $hoja->turno);

        return response($papel, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'X-Nombre-Archivo' => $nombre,
        ]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $datos = $this->validar($request);
        $this->exigirTurnoLibre($datos['fecha'], $datos['turno']);

        $hoja = Cuadrador::guardar($datos);

        return response()->json($this->comoDetalle($hoja), 201);
    }

    public function actualizar(Request $request, HojaCuadre $hoja): JsonResponse
    {
        $this->exigirPropia($request, $hoja);

        $datos = $this->validar($request);
        $this->exigirTurnoLibre($datos['fecha'], $datos['turno'], $hoja);

        return response()->json($this->comoDetalle(Cuadrador::guardar($datos, $hoja)));
    }

    // --- apoyo --------------------------------------------------------------

    private function validar(Request $request): array
    {
        $datos = $request->validate([
            // "Hoy" es el de la empresa, no el del servidor: en Puerto Rico
            // son las ocho de la noche cuando en el servidor ya es mañana.
            'fecha' => ['required', 'date', 'before_or_equal:'.Cuadrador::hoy()],
            'turno' => ['required', Rule::in(HojaCuadre::TURNOS)],
            'efectivo_inicial' => ['nullable', 'string', 'max:20'],
            'ventas_lectura' => ['nullable', 'string', 'max:20'],
            'efectivo_cambio' => ['nullable', 'string', 'max:20'],
            'tarjeta' => ['nullable', 'string', 'max:20'],
            'ath_movil' => ['nullable', 'string', 'max:20'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'gastos' => ['nullable', 'array', 'max:100'],
            'gastos.*.descripcion' => ['required', 'string', 'min:2', 'max:200'],
            'gastos.*.monto' => ['required', 'string', 'max:20'],
        ], [
            'fecha.before_or_equal' => 'No se puede cuadrar un turno que todavía no ha pasado.',
            'gastos.*.descripcion.required' => 'Cada gasto necesita decir de qué es.',
        ]);

        // El dinero llega como texto y se guarda en centavos enteros (ARQ-09).
        foreach (['efectivo_inicial', 'ventas_lectura', 'efectivo_cambio', 'tarjeta', 'ath_movil'] as $campo) {
            $centavos = Precio::aCentavos($datos[$campo] ?? null) ?? 0;

            if ($centavos < 0) {
                throw ValidationException::withMessages([$campo => 'No puede ser un número negativo.']);
            }

            $datos[$campo] = $centavos;
        }

        foreach ($datos['gastos'] ?? [] as $i => $gasto) {
            $monto = Precio::aCentavos($gasto['monto']);

            if ($monto === null || $monto <= 0) {
                throw ValidationException::withMessages([
                    "gastos.{$i}.monto" => 'El valor del gasto tiene que ser un número mayor que cero.',
                ]);
            }

            $datos['gastos'][$i]['monto'] = $monto;
        }

        return $datos;
    }

    /** Una hoja por fecha y turno: dos cierres del mismo turno no cuadran nada. */
    private function exigirTurnoLibre(string $fecha, string $turno, ?HojaCuadre $salvo = null): void
    {
        $existe = HojaCuadre::query()
            ->whereDate('fecha', $fecha)
            ->where('turno', $turno)
            ->when($salvo, fn ($q) => $q->where('id', '!=', $salvo->id))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'turno' => 'Ya hay una hoja de cuadre para ese día y turno.',
            ]);
        }
    }

    /** Quien no ve las de otros tampoco abre una ajena (ARQ-21: responde 404). */
    private function exigirPropia(Request $request, HojaCuadre $hoja): void
    {
        $membresia = $request->attributes->get('membresia');

        if (Permisos::permite($membresia, Permisos::CAJA_VER_TODAS)) {
            return;
        }

        if ($hoja->usuario_id !== $request->user()->id) {
            abort(404);
        }
    }

    private function comoResumen(HojaCuadre $h): array
    {
        $t = $h->totales();

        return [
            'id' => $h->ulid,
            'fecha' => $h->fecha->toDateString(),
            'turno' => $h->turno,
            'efectivo_inicial' => Precio::aTexto($h->efectivo_inicial_centavos),
            'ventas_lectura' => Precio::aTexto($h->ventas_lectura_centavos),
            'efectivo_cambio' => Precio::aTexto($h->efectivo_cambio_centavos),
            'total_efectivo' => Precio::aTexto($t['total_efectivo']),
            'gastos' => Precio::aTexto($t['gastos']),
            'a_depositar' => Precio::aTexto($t['a_depositar']),
            'cuadro' => $h->usuario?->nombreCompleto(),
        ];
    }

    private function comoDetalle(HojaCuadre $h): array
    {
        $t = $h->totales();
        $facturado = Cuadrador::loFacturado($h->fecha->toDateString(), $h->turno);

        $comparacion = Cuadre::comparar(
            [
                'ventas' => $h->ventas_lectura_centavos,
                'tarjeta' => $h->tarjeta_centavos,
                'ath_movil' => $h->ath_movil_centavos,
            ],
            ['ventas' => $facturado['ventas'], 'tarjeta' => $facturado['tarjeta'], 'ath_movil' => $facturado['ath_movil']],
        );

        return $this->comoResumen($h) + [
            'tarjeta' => Precio::aTexto($h->tarjeta_centavos),
            'ath_movil' => Precio::aTexto($h->ath_movil_centavos),
            'venta_y_cambio' => Precio::aTexto($t['venta_y_cambio']),
            'total_ventas' => Precio::aTexto($t['total_ventas']),
            'notas' => $h->notas,
            'gastos_detalle' => $h->gastos->map(fn (GastoCuadre $g) => [
                'id' => $g->ulid,
                'descripcion' => $g->descripcion,
                'monto' => Precio::aTexto($g->monto_centavos),
            ])->all(),
            'facturado' => [
                'facturas' => $facturado['facturas'],
                'ventas' => Precio::aTexto($facturado['ventas']),
                'tarjeta' => Precio::aTexto($facturado['tarjeta']),
                'ath_movil' => Precio::aTexto($facturado['ath_movil']),
                'efectivo' => Precio::aTexto($facturado['efectivo']),
            ],
            'comparacion' => array_map(fn ($c) => [
                'declarado' => Precio::aTexto($c['declarado']),
                'facturado' => Precio::aTexto($c['facturado']),
                'diferencia' => Precio::aTexto($c['diferencia']),
                'cuadra' => $c['cuadra'],
            ], $comparacion),
        ];
    }
}
