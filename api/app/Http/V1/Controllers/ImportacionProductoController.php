<?php

namespace App\Http\V1\Controllers;

use App\Domain\Csv;
use App\Domain\Precio;
use App\Models\Importacion;
use App\Models\Producto;
use App\Soporte\ImportadorProductos;
use App\Soporte\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class ImportacionProductoController extends Controller
{
    /** Paso 1: la plantilla, con dos ejemplos dentro (PRO-07). */
    public function plantilla(): Response
    {
        return $this->comoDescarga(ImportadorProductos::plantilla(), 'plantilla-productos.csv');
    }

    /** Paso 2: se lee el archivo y se dice qué pasaría. Nada se guarda todavía. */
    public function previsualizar(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => ['required', 'file', 'max:5120', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            'actualizar_existentes' => ['boolean'],
        ], [
            'archivo.max' => 'El archivo no puede pesar más de 5 MB.',
            'archivo.mimetypes' => 'Tiene que ser un archivo CSV. Si lo tienes en Excel, guárdalo como CSV.',
        ]);

        $archivo = $request->file('archivo');
        $contenido = file_get_contents($archivo->getRealPath()) ?: '';

        if (trim($contenido) === '') {
            return response()->json([
                'message' => 'El archivo está vacío.',
                'codigo' => 'archivo_vacio',
            ], 422);
        }

        $importacion = ImportadorProductos::previsualizar(
            $contenido,
            $archivo->getClientOriginalName(),
            $request->boolean('actualizar_existentes'),
        );

        if ($importacion->filas_total === 0) {
            return response()->json([
                'message' => 'No se encontró ninguna fila. Revisa que el archivo tenga encabezados y al menos un producto.',
                'codigo' => 'sin_filas',
            ], 422);
        }

        return response()->json($this->comoArreglo($importacion));
    }

    /** Paso 3: se aplica entera o no se aplica nada (PRO-08). */
    public function confirmar(Importacion $importacion): JsonResponse
    {
        if ($importacion->estado !== 'previsualizada') {
            return response()->json([
                'message' => 'Esta importación ya se aplicó o se descartó.',
                'codigo' => 'importacion_cerrada',
            ], 409);
        }

        if (! $importacion->puedeAplicarse()) {
            return response()->json([
                'message' => 'Ninguna fila del archivo se puede importar. Corrige los errores y vuelve a subirlo.',
                'codigo' => 'nada_que_importar',
            ], 422);
        }

        $resultado = ImportadorProductos::aplicar($importacion);

        return response()->json([
            'importacion' => $this->comoArreglo($importacion->fresh()),
            'nuevos' => $resultado['nuevos'],
            'actualizados' => $resultado['actualizados'],
        ]);
    }

    public function descartar(Importacion $importacion): JsonResponse
    {
        if ($importacion->estado === 'previsualizada') {
            $importacion->update(['estado' => 'descartada']);
        }

        return response()->json(['mensaje' => 'Importación descartada.']);
    }

    /** El informe de lo que hay que arreglar, para abrirlo junto al archivo. */
    public function errores(Importacion $importacion): Response
    {
        return $this->comoDescarga(
            ImportadorProductos::informeDeErrores($importacion),
            'errores-'.pathinfo($importacion->archivo_nombre, PATHINFO_FILENAME).'.csv',
        );
    }

    /** PRO-09: la vista filtrada, tal como se está viendo, con sus filtros anotados. */
    public function exportar(Request $request): Response
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'categoria' => ['nullable', 'string', 'size:26'],
            'suplidor' => ['nullable', 'string', 'size:26'],
            'existencia' => ['nullable', Rule::in(['agotado', 'bajo', 'normal'])],
            'tipo' => ['nullable', Rule::in(['producto', 'servicio'])],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos', 'todos'])],
        ]);

        $estado = $filtros['estado'] ?? 'activos';
        $veCostos = Permisos::permite($request->attributes->get('membresia'), Permisos::COSTOS_VER);

        $productos = Producto::query()
            ->with(['categoria:id,nombre', 'suplidor:id,razon_social,nombre_comercial'])
            ->buscar($filtros['buscar'] ?? null)
            ->conExistencia($filtros['existencia'] ?? null)
            ->when($estado === 'activos', fn ($q) => $q->where('activo', true))
            ->when($estado === 'inactivos', fn ($q) => $q->where('activo', false))
            ->when(($filtros['tipo'] ?? null) === 'servicio', fn ($q) => $q->where('es_servicio', true))
            ->when(($filtros['tipo'] ?? null) === 'producto', fn ($q) => $q->where('es_servicio', false))
            ->when($filtros['categoria'] ?? null, fn ($q, $ulid) => $q->whereHas('categoria', fn ($c) => $c->where('ulid', $ulid)))
            ->when($filtros['suplidor'] ?? null, fn ($q, $ulid) => $q->whereHas('suplidor', fn ($s) => $s->where('ulid', $ulid)))
            ->orderBy('nombre')
            ->limit(10000)
            ->get();

        $encabezados = array_values(ImportadorProductos::COLUMNAS);
        if (! $veCostos) {
            // El costo no sale en el archivo de quien no lo ve en pantalla.
            $encabezados = array_values(array_diff($encabezados, ['Costo']));
        }
        $encabezados[] = 'Activo';

        $filas = $productos->map(function (Producto $p) use ($veCostos) {
            $fila = [
                $p->nombre,
                $p->sku ?? '',
                $p->codigo_barras ?? '',
                $p->categoria?->nombre ?? '',
                $p->suplidor?->nombre() ?? '',
                $p->unidad,
            ];

            if ($veCostos) {
                $fila[] = Precio::aTexto($p->costo_centavos);
            }

            return array_merge($fila, [
                Precio::aTexto($p->precio_centavos),
                Precio::tasaATexto($p->impuesto_milesimas),
                $p->es_servicio ? '' : rtrim(rtrim((string) $p->existencia, '0'), '.'),
                $p->es_servicio ? '' : rtrim(rtrim((string) $p->existencia_minima, '0'), '.'),
                $p->es_servicio ? 'si' : 'no',
                $p->activo ? 'si' : 'no',
            ]);
        })->all();

        // Los filtros aplicados quedan en el nombre del archivo, para que nadie
        // confunda una vista filtrada con el catálogo completo (PRO-09).
        $partes = array_filter([
            'productos',
            $filtros['buscar'] ?? null ? 'busqueda' : null,
            $filtros['existencia'] ?? null,
            $filtros['tipo'] ?? null,
            $estado !== 'activos' ? $estado : null,
        ]);

        return $this->comoDescarga(
            Csv::escribir($encabezados, $filas),
            implode('-', $partes).'-'.now()->format('Y-m-d').'.csv',
        );
    }

    private function comoDescarga(string $contenido, string $nombre): Response
    {
        return response($contenido, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'X-Nombre-Archivo' => $nombre,
        ]);
    }

    private function comoArreglo(Importacion $i): array
    {
        return [
            'id' => $i->ulid,
            'archivo' => $i->archivo_nombre,
            'estado' => $i->estado,
            'actualizar_existentes' => $i->actualizar_existentes,
            'total' => $i->filas_total,
            'nuevas' => $i->filas_nuevas,
            'actualiza' => $i->filas_actualiza,
            'errores' => $i->filas_error,
            'puede_aplicarse' => $i->puedeAplicarse(),
            // Solo las primeras filas: la vista previa es para entender, no para leerlo todo.
            'filas' => collect($i->filas ?? [])->take(100)->map(fn ($f) => [
                'numero' => $f['numero'],
                'accion' => $f['accion'],
                'nombre' => $f['nombre'],
                'sku' => $f['sku'],
                'errores' => $f['errores'],
            ])->values()->all(),
        ];
    }
}
