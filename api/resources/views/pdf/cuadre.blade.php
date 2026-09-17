{{--
  La hoja de cuadre en papel (CAJ-07).

  Maquetada con tablas a propósito: dompdf no entiende flex ni grid. Es un
  documento que se archiva y se lleva al banco, así que manda la claridad de las
  cifras por encima de cualquier adorno.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Hoja de cuadre {{ $hoja->fecha->format('d/m/Y') }} {{ mb_strtoupper($hoja->turno) }}</title>
    @php
        // Un cero no lleva signo: "−0.00" se lee como un descuido.
        $resta = fn (int $centavos) => ($centavos > 0 ? '−' : '').$n($centavos);
    @endphp
    <style>
        @page { margin: 1.6cm 1.4cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #111; }
        h1 { font-size: 15pt; margin: 0 0 2pt; }
        h2 { font-size: 10pt; margin: 14pt 0 4pt; text-transform: uppercase; letter-spacing: .5pt; color: #555; }
        .sub { color: #555; font-size: 9pt; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .membrete td { vertical-align: top; padding: 0; }
        .membrete .derecha { text-align: right; }
        .regla { border-bottom: 1.5pt solid #111; height: 8pt; }
        .cifras td { padding: 3pt 0; }
        .cifras .valor { text-align: right; white-space: nowrap; }
        .calculado td { background: #eef2f1; font-weight: bold; padding: 5pt 6pt; }
        .total td { border-top: 1.5pt solid #111; font-size: 12pt; font-weight: bold; padding-top: 6pt; }
        .gastos th { text-align: left; border-bottom: 1pt solid #111; padding: 3pt 0; font-size: 8pt; text-transform: uppercase; color: #555; }
        .gastos td { border-bottom: .5pt solid #ccc; padding: 4pt 0; }
        .gastos .valor { text-align: right; }
        .mitad { width: 48%; vertical-align: top; }
        .separador { width: 4%; }
        .nota { margin-top: 10pt; font-size: 9pt; color: #333; }
        .pie { margin-top: 18pt; font-size: 8pt; color: #777; }
        .descuadre { color: #a3352c; font-weight: bold; }
    </style>
</head>
<body>

<table class="membrete">
    <tr>
        <td>
            <h1>{{ $empresa->nombre() }}</h1>
            @if ($empresa->registro_comerciante)<p class="sub">Registro {{ $empresa->registro_comerciante }}</p>@endif
            @if ($empresa->direccion_fisica)<p class="sub">{{ $empresa->direccion_fisica }}</p>@endif
        </td>
        <td class="derecha">
            <h1>Hoja de cuadre</h1>
            <p class="sub">{{ $hoja->fecha->format('d/m/Y') }} · turno de {{ $hoja->turno === 'am' ? 'mañana' : 'tarde' }}</p>
            @if ($hoja->usuario)<p class="sub">Cuadró {{ $hoja->usuario->nombreCompleto() }}</p>@endif
        </td>
    </tr>
</table>

<div class="regla"></div>

<table>
    <tr>
        <td class="mitad">
            <table class="cifras">
                <tr><td>Efectivo al comienzo</td><td class="valor">{{ $n($hoja->efectivo_inicial_centavos) }}</td></tr>
                <tr><td>Ventas (según lectura)</td><td class="valor">{{ $n($hoja->ventas_lectura_centavos) }}</td></tr>
                <tr class="calculado"><td>Total venta y cambio</td><td class="valor">{{ $n($t['venta_y_cambio']) }}</td></tr>
                <tr><td>Efectivo para cambio</td><td class="valor">{{ $resta($hoja->efectivo_cambio_centavos) }}</td></tr>
            </table>
        </td>
        <td class="separador"></td>
        <td class="mitad">
            <table class="cifras">
                <tr><td>ATH / Visa / Mastercard</td><td class="valor">{{ $resta($hoja->tarjeta_centavos) }}</td></tr>
                <tr><td>ATH Móvil</td><td class="valor">{{ $resta($hoja->ath_movil_centavos) }}</td></tr>
                <tr class="calculado"><td>Total efectivo</td><td class="valor">{{ $n($t['total_efectivo']) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<h2>Compras y gastos</h2>

<table class="gastos">
    <thead>
        <tr><th>Descripción</th><th style="text-align: right; width: 22%;">Valor</th></tr>
    </thead>
    <tbody>
        @forelse ($hoja->gastos as $gasto)
            <tr><td>{{ $gasto->descripcion }}</td><td class="valor">{{ $n($gasto->monto_centavos) }}</td></tr>
        @empty
            <tr><td colspan="2" style="color: #777;">Sin gastos en este turno.</td></tr>
        @endforelse
    </tbody>
</table>

<h2>Total hoja de cuadre</h2>

<table class="cifras">
    <tr><td>Total de compras y gastos</td><td class="valor">{{ $resta($t['gastos']) }}</td></tr>
    <tr class="total">
        <td>Efectivo para depositar</td>
        <td class="valor {{ $t['a_depositar'] < 0 ? 'descuadre' : '' }}">{{ $n($t['a_depositar']) }}</td>
    </tr>
    <tr><td>Total ventas</td><td class="valor">{{ $n($t['total_ventas']) }}</td></tr>
</table>

{{-- Lo que el sistema facturó en el mismo turno: el control cruzado (CAJ-12). --}}
<h2>Lo que el sistema facturó en este turno</h2>

<table class="cifras">
    <tr>
        <td>Ventas facturadas ({{ $facturado['facturas'] }} factura(s))</td>
        <td class="valor">{{ $n($facturado['ventas']) }}</td>
        <td class="valor {{ $comparacion['ventas']['cuadra'] ? '' : 'descuadre' }}" style="width: 22%;">
            {{ $comparacion['ventas']['cuadra'] ? 'cuadra' : 'diferencia '.$n($comparacion['ventas']['diferencia']) }}
        </td>
    </tr>
    <tr>
        <td>Cobrado con tarjeta</td>
        <td class="valor">{{ $n($facturado['tarjeta']) }}</td>
        <td class="valor {{ $comparacion['tarjeta']['cuadra'] ? '' : 'descuadre' }}">
            {{ $comparacion['tarjeta']['cuadra'] ? 'cuadra' : 'diferencia '.$n($comparacion['tarjeta']['diferencia']) }}
        </td>
    </tr>
    <tr>
        <td>Cobrado con ATH Móvil</td>
        <td class="valor">{{ $n($facturado['ath_movil']) }}</td>
        <td class="valor {{ $comparacion['ath_movil']['cuadra'] ? '' : 'descuadre' }}">
            {{ $comparacion['ath_movil']['cuadra'] ? 'cuadra' : 'diferencia '.$n($comparacion['ath_movil']['diferencia']) }}
        </td>
    </tr>
</table>

@if ($hoja->notas)
    <p class="nota"><b>Notas:</b> {{ $hoja->notas }}</p>
@endif

<p class="pie">
    Impresa el {{ $impresa }} · {{ $empresa->nombre() }} · All in One Place
</p>

</body>
</html>
