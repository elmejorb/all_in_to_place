#!/usr/bin/env bash
# Regenera docs/matriz-requisitos.csv a partir de las tablas de requisitos
# de los documentos numerados. Uso: bash docs/generar-matriz.sh
set -euo pipefail
cd "$(dirname "$0")"

salida=matriz-requisitos.csv

{
  printf 'ID,Modulo,Prioridad,Documento,Requisito\n'
  for archivo in [0-9]*.md; do
    awk -v doc="$archivo" -F' *\\| *' '
      BEGIN {
        m["PR"]  = "Principios"
        m["ARQ"] = "Arquitectura"
        m["ROL"] = "Roles y permisos"
        m["AUT"] = "Acceso y cuentas"
        m["EMP"] = "Empresa y sucursales"
        m["SUP"] = "Suplidores"
        m["CAT"] = "Categorias"
        m["PRO"] = "Productos"
        m["INV"] = "Inventario"
        m["CLI"] = "Clientes"
        m["FAC"] = "Facturacion"
        m["CAJ"] = "Hoja de Cuadre"
        m["RPT"] = "Panel y reportes"
        m["NOT"] = "Notificaciones"
        m["ADM"] = "Consola de plataforma"
        m["IU"]  = "Interfaz"
        m["RNF"] = "No funcionales"
        m["MIG"] = "Migracion"
        m["SEG"] = "Seguridad"
        m["CAL"] = "Proceso y calidad"
      }
      # Solo filas de tabla de requisitos: ID, prioridad valida y texto.
      # Descarta tablas que citan identificadores en otras columnas.
      NF >= 5 && $2 ~ /^[A-Z]+-[0-9]+$/ && $3 ~ /^(MVP|F2|F3|Guía)$/ {
        id = $2; pri = $3; txt = $4
        split(id, p, "-")
        mod = (p[1] in m) ? m[p[1]] : p[1]
        gsub(/"/, "\"\"", txt)
        printf "%s,%s,%s,%s,\"%s\"\n", id, mod, pri, doc, txt
      }
    ' "$archivo"
  done
} > "$salida"

printf 'Requisitos en %s: %d\n' "$salida" "$(($(wc -l < "$salida") - 1))"
