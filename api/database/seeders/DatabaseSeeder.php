<?php

namespace Database\Seeders;

use App\Domain\Precio;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Membresia;
use App\Models\Suplidor;
use App\Models\Usuario;
use App\Models\UsuarioPlataforma;
use Illuminate\Database\Seeder;
use App\Soporte\ContextoRls;
use App\Soporte\Inventario;
use Illuminate\Support\Facades\DB;

/**
 * Escenario de prueba usable desde el primer arranque (CAL-07).
 *
 * Corre sobre la conexión dueña del esquema: el rol de la aplicación no puede
 * crear empresas ni usuarios, y así debe ser (SEG-38, ADM-01).
 */
class DatabaseSeeder extends Seeder
{
    public const CLAVE = 'Clave.Segura.1';

    public function run(): void
    {
        DB::setDefaultConnection('pgsql_migrator');

        $alamo = Empresa::create([
            'nombre_legal' => 'Panadería El Álamo Inc.',
            'nombre_comercial' => 'Panadería El Álamo',
            'registro_comerciante' => '123-456-789',
            'telefono' => '787-740-6632',
            'email' => 'hola@elalamo.test',
            'direccion_fisica' => 'Magnolia Gardens L27, Avenida Magnolia, Bayamón, PR 00956',
            'pais' => 'PR',
            'moneda' => 'USD',
            'zona_horaria' => 'America/Puerto_Rico',
            'idioma' => 'es',
            'estado' => 'activa',
            // 11.5% = 10.5 estatal + 1 municipal (FAC-04).
            'impuesto_desglose' => [
                ['nombre' => 'Estatal', 'milesimas' => 10500],
                ['nombre' => 'Municipal', 'milesimas' => 1000],
            ],
        ]);

        $santaMonica = Empresa::create([
            'nombre_legal' => 'Panadería El Álamo Santa Mónica LLC',
            'nombre_comercial' => 'El Álamo Santa Mónica',
            'telefono' => '787-788-3083',
            'direccion_fisica' => 'Calle Jerry Rivas A13, Santa Mónica, Bayamón, PR 00957',
            'pais' => 'PR',
            'moneda' => 'USD',
            'zona_horaria' => 'America/Puerto_Rico',
            'estado' => 'activa',
        ]);

        // Empresa de otro país y en otro estado: sirve para probar aislamiento y solo lectura.
        $innovacion = Empresa::create([
            'nombre_legal' => 'Innovación Digital S.A.S.',
            'nombre_comercial' => 'Innovación Digital',
            'telefono' => '300-479-8801',
            'direccion_fisica' => 'Carrera 8 Calle 7 No 6-25, Planeta Rica, Córdoba',
            'pais' => 'CO',
            'moneda' => 'COP',
            'zona_horaria' => 'America/Bogota',
            'estado' => 'suspendida',
            'impuesto_desglose' => [['nombre' => 'IVA', 'milesimas' => 19000]],
        ]);

        // Un usuario por rol en El Álamo (04-roles-y-permisos.md).
        $roles = [
            ['Pedro', 'Rivera', 'pedro@elalamo.test', 'propietario', null],
            ['Marta', 'Suárez', 'marta@elalamo.test', 'administrador', null],
            ['Gina', 'Muñoz', 'gina@elalamo.test', 'gerente', null],
            ['Emily', 'Martínez', 'emily@elalamo.test', 'empleado', 'mostrador'],
            ['Carmina', 'Ricardo', 'carmina@elalamo.test', 'empleado', 'almacen'],
            ['Julio', 'Cintrón', 'julio@elalamo.test', 'contratista', null],
            ['Sonia', 'Delgado', 'sonia@elalamo.test', 'contador', null],
        ];

        foreach ($roles as [$nombres, $apellidos, $email, $rol, $perfil]) {
            $usuario = Usuario::create([
                'nombres' => $nombres,
                'apellidos' => $apellidos,
                'email' => $email,
                'password' => self::CLAVE,
                'activo' => true,
            ]);

            Membresia::create([
                'empresa_id' => $alamo->id,
                'usuario_id' => $usuario->id,
                'rol' => $rol,
                'perfil' => $perfil,
                'activa' => true,
            ]);
        }

        // Persona con dos empresas: prueba el selector (EMP-05).
        $luis = Usuario::create([
            'nombres' => 'Luis',
            'apellidos' => 'Ricardo',
            'email' => 'luis@elalamo.test',
            'password' => self::CLAVE,
            'activo' => true,
        ]);

        Membresia::create(['empresa_id' => $alamo->id, 'usuario_id' => $luis->id, 'rol' => 'administrador', 'activa' => true]);
        Membresia::create(['empresa_id' => $santaMonica->id, 'usuario_id' => $luis->id, 'rol' => 'propietario', 'activa' => true]);

        // Dueño de la empresa suspendida: prueba el modo solo lectura (ADM-03).
        $carlos = Usuario::create([
            'nombres' => 'Carlos',
            'apellidos' => 'Martínez',
            'email' => 'carlos@innovacion.test',
            'password' => self::CLAVE,
            'activo' => true,
        ]);

        Membresia::create(['empresa_id' => $innovacion->id, 'usuario_id' => $carlos->id, 'rol' => 'propietario', 'activa' => true]);

        // Usuario sin empresa: debe rebotar al entrar (ADM-20).
        Usuario::create([
            'nombres' => 'Huérfano',
            'apellidos' => 'Sin Empresa',
            'email' => 'sinempresa@aiop.test',
            'password' => self::CLAVE,
            'activo' => true,
        ]);

        // Usuario desactivado: no debe poder entrar (AUT-01).
        Usuario::create([
            'nombres' => 'Inactivo',
            'apellidos' => 'Baja',
            'email' => 'inactivo@elalamo.test',
            'password' => self::CLAVE,
            'activo' => false,
        ]);

        $this->suplidores($alamo->id, [
            ['Distribuidora Harinas del Caribe Inc.', 'Harinas del Caribe', '4578', '787-555-4545', 'Margarita Argumedo', '30 días', true],
            ['Lácteos de la Montaña LLC', 'Lácteos La Montaña', '8891', '787-555-1122', 'Carlos Estrada', 'Contado', true],
            ['Empaques y Cajas del Este', null, '2210', '787-555-9080', 'Rosa Núñez', '15 días', true],
            ['Azúcares Refinados PR', 'Azúcar PR', '7745', '787-555-3311', 'Iván Colón', '30 días', true],
            ['Suplidor Antiguo Cerrado', null, '1001', null, null, null, false],
        ]);

        // Las mismas categorías que usa hoy la panadería.
        $this->categorias($alamo->id, [
            ['Dulces', 'Postres y repostería dulce', 'rosa'],
            ['Pastelería', 'Pan y productos horneados', 'ambar'],
            ['Artesanal', 'Elaboración propia por encargo', 'verde'],
            ['Bebidas', null, 'turquesa'],
        ]);

        $this->categorias($santaMonica->id, [
            ['Pastelería', null, 'ambar'],
        ]);

        $this->categorias($innovacion->id, [
            ['Papelería', 'Insumos de oficina', 'azul'],
        ]);

        $this->suplidores($santaMonica->id, [
            ['Café Yaucono Distribución', 'Yaucono', '3301', '787-555-7788', 'Nelson Vega', '30 días', true],
        ]);

        // Un suplidor de la otra empresa, para que las pruebas de aislamiento
        // tengan algo real que intentar alcanzar.
        $this->suplidores($innovacion->id, [
            ['Papelería Planeta Rica S.A.S.', 'Papelería Planeta', 'CO-889', '300-555-2211', 'Andrea Gómez', 'Contado', true],
        ]);

        // Los productos que se ven en el sistema actual, ahora con precio de
        // venta y margen, que es lo que allá falta (PRO-02).
        $this->productos($alamo->id, [
            // nombre, sku, barras, categoría, suplidor, unidad, costo, precio, impuesto, existencia, mínimo, servicio
            ['Pan de Leche relleno de chocolate', 'PAN-001', '7451000010015', 'Pastelería', 'Harinas del Caribe', 'unidad', '5.99', '9.50', '11.5', 115, 20, false],
            ['Pan Horneado con arequipe', 'PAN-002', '7451000010022', 'Pastelería', 'Harinas del Caribe', 'unidad', '35.99', '52.00', '11.5', 25, 5, false],
            ['Pan Pinzza Relleno', 'PAN-003', null, 'Pastelería', 'Harinas del Caribe', 'unidad', '42.99', '65.00', '11.5', 33, 5, false],
            ['Torta chocolate media libra', 'TOR-001', null, 'Dulces', null, 'libra', '60.00', '95.00', '11.5', 10, 5, false],
            ['Dulce de Guayaba con Leche', 'DUL-001', null, 'Artesanal', null, 'unidad', '49.99', '75.00', '11.5', 28, 5, false],
            ['Donas rellenas de arequipe', 'DON-001', '7451000010039', 'Dulces', 'Lácteos La Montaña', 'docena', '15.00', '24.00', '11.5', 40, 10, false],
            ['70 Mini Mallorcas Jamón Queso y Huevo', 'MAL-070', null, 'Pastelería', 'Harinas del Caribe', 'caja', '25.00', '45.00', '11.5', 100, 10, false],
            ['Café colado 12 oz', 'CAF-012', null, 'Bebidas', null, 'unidad', '0.75', '2.50', '7', 0, 24, false],
            ['Jugo natural de china', 'JUG-001', null, 'Bebidas', null, 'unidad', '1.10', '3.00', '7', 8, 12, false],
            ['Bizcocho por encargo', 'ENC-001', null, 'Artesanal', null, 'servicio', '0', '150.00', '11.5', 0, 0, true],
            ['Decoración de mesa dulce', 'ENC-002', null, 'Artesanal', null, 'servicio', '0', '250.00', '11.5', 0, 0, true],
            ['Pan sobao descontinuado', 'PAN-OLD', null, 'Pastelería', null, 'unidad', '3.00', '5.00', '11.5', 0, 0, false],
        ]);

        // Ese último queda inactivo, para poder probar el filtro.
        Producto::withoutGlobalScope('empresa')
            ->where('empresa_id', $alamo->id)
            ->where('sku', 'PAN-OLD')
            ->update(['activo' => false]);

        $this->productos($santaMonica->id, [
            ['Pan de agua', 'PA-001', null, 'Pastelería', 'Yaucono', 'unidad', '0.50', '1.25', '11.5', 60, 20, false],
        ]);

        $this->productos($innovacion->id, [
            ['Resma de papel carta', 'PAP-001', null, 'Papelería', 'Papelería Planeta', 'caja', '12000', '18000', '19', 15, 5, false],
        ]);

        $this->clientes($alamo->id, [
            // nombre, tipo, identificacion, telefono, email, exento, certificado, terminos, credito
            ['Cafetería La Esquina', 'empresa', '660-12-3456', '787-555-2020', 'pedidos@laesquina.test', false, null, '15 dias', '500.00'],
            ['Colegio San Antonio', 'empresa', '660-98-7654', '787-555-3030', 'compras@sanantonio.test', true, 'EXE-2024-114', '30 dias', '1500.00'],
            ['María Fernández', 'persona', null, '787-555-4040', null, false, null, null, '0'],
            ['José Rolón', 'persona', null, '787-555-5050', 'jrolon@correo.test', false, null, 'contado', '0'],
            ['Hotel Bayamón Plaza', 'empresa', '660-44-5566', '787-555-6060', null, false, null, '30 dias', '3000.00'],
            ['Cliente Antiguo Inactivo', 'persona', null, null, null, false, null, null, '0'],
        ]);

        Cliente::withoutGlobalScope('empresa')
            ->where('empresa_id', $alamo->id)
            ->where('nombre', 'Cliente Antiguo Inactivo')
            ->update(['activo' => false]);

        $this->clientes($santaMonica->id, [
            ['Panadería vecina', 'empresa', null, '787-555-7070', null, false, null, null, '0'],
        ]);

        $this->clientes($innovacion->id, [
            ['Alcaldía de Planeta Rica', 'empresa', '900123456-7', '300-555-8080', null, true, 'RES-2024-9', '30 dias', '10000.00'],
        ]);

        // Consola de plataforma: otro ámbito, otra tabla (ARQ-03).
        UsuarioPlataforma::create([
            'nombres' => 'Laura',
            'apellidos' => 'Mojica',
            'email' => 'laura@aiop.test',
            'password' => self::CLAVE,
            'rol' => 'superadmin',
            'activo' => true,
        ]);

        UsuarioPlataforma::create([
            'nombres' => 'Soporte',
            'apellidos' => 'AIOP',
            'email' => 'soporte@aiop.test',
            'password' => self::CLAVE,
            'rol' => 'soporte',
            'activo' => true,
        ]);
    }

    private function clientes(int $empresaId, array $filas): void
    {
        foreach ($filas as [$nombre, $tipo, $identificacion, $telefono, $email, $exento, $certificado, $terminos, $credito]) {
            Cliente::withoutGlobalScope('empresa')->create([
                'empresa_id' => $empresaId,
                'nombre' => $nombre,
                'tipo' => $tipo,
                'identificacion' => $identificacion,
                'telefono' => $telefono,
                'email' => $email,
                'exento' => $exento,
                'certificado_exencion' => $certificado,
                'terminos_pago' => $terminos,
                'limite_credito_centavos' => Precio::aCentavos($credito) ?? 0,
                'activo' => true,
            ]);
        }
    }

    /** Crea productos y su existencia de apertura como movimiento (INV-01). */
    private function productos(int $empresaId, array $filas): void
    {
        // El registrador de inventario filtra por la empresa del contexto, igual
        // que en producción: hay que abrirlo antes de sembrar (ARQ-04).
        ContextoRls::fijar(ContextoRls::EMPRESA, $empresaId);

        foreach ($filas as [$nombre, $sku, $barras, $categoria, $suplidor, $unidad, $costo, $precio, $impuesto, $existencia, $minimo, $servicio]) {
            $producto = Producto::withoutGlobalScope('empresa')->create([
                'empresa_id' => $empresaId,
                'nombre' => $nombre,
                'sku' => $sku,
                'codigo_barras' => $barras,
                'categoria_id' => $categoria
                    ? Categoria::withoutGlobalScope('empresa')->where('empresa_id', $empresaId)->where('nombre', $categoria)->value('id')
                    : null,
                'suplidor_id' => $suplidor
                    ? Suplidor::withoutGlobalScope('empresa')->where('empresa_id', $empresaId)
                        ->where(fn ($q) => $q->where('nombre_comercial', $suplidor)->orWhere('razon_social', $suplidor))->value('id')
                    : null,
                'unidad' => $unidad,
                'costo_centavos' => Precio::aCentavos($costo) ?? 0,
                'precio_centavos' => Precio::aCentavos($precio) ?? 0,
                'impuesto_milesimas' => Precio::tasaAMilesimas($impuesto) ?? 0,
                'existencia_minima' => $minimo,
                'es_servicio' => $servicio,
                'activo' => true,
            ]);

            if (! $servicio && $existencia > 0) {
                Inventario::registrar($producto, 'apertura', (float) $existencia, 'saldo de apertura', null, $producto->costo_centavos ?: null);
            }
        }

        ContextoRls::fijar(ContextoRls::EMPRESA, '');
    }

    /** @param  list<array{0:string,1:?string,2:string}>  $filas */
    private function categorias(int $empresaId, array $filas): void
    {
        foreach ($filas as $i => [$nombre, $descripcion, $color]) {
            Categoria::withoutGlobalScope('empresa')->create([
                'empresa_id' => $empresaId,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'color' => $color,
                'orden' => $i,
            ]);
        }
    }

    /** @param  list<array{0:string,1:?string,2:?string,3:?string,4:?string,5:?string,6:bool}>  $filas */
    private function suplidores(int $empresaId, array $filas): void
    {
        foreach ($filas as [$razon, $comercial, $numero, $telefono, $vendedor, $terminos, $activo]) {
            Suplidor::withoutGlobalScope('empresa')->create([
                'empresa_id' => $empresaId,
                'razon_social' => $razon,
                'nombre_comercial' => $comercial,
                'numero_cliente' => $numero,
                'telefono' => $telefono,
                'vendedor' => $vendedor,
                'terminos_pago' => $terminos,
                'activo' => $activo,
            ]);
        }
    }
}
