# cfdi-inbox/

Carpeta para los XMLs de CFDI que descargas del portal SAT.

## Cómo usar

1. Timbras tu factura en el portal SAT (como siempre).
2. Descargas el XML que te entrega el SAT.
3. Arrastras el archivo aquí (`cfdi-inbox/`). Puedes nombrarlo como quieras, pero recomiendo algo descriptivo:
   - `2026-04-14-productos-quimicos-INV001.xml`
   - `2026-05-30-cliente-x-INV012.xml`
4. En una sesión de Claude con el MCP de Odoo cargado, pide:
   > "Importa el XML cfdi-inbox/2026-04-14-productos-quimicos-INV001.xml como factura de cliente"

## Tipos

- **Factura de cliente (customer)** — un CFDI que TÚ emitiste cobrándole a alguien.
- **Factura de proveedor (vendor)** — un CFDI que TE emitió un proveedor.

Si dudas, casi siempre los XMLs que descargas del portal SAT son los que tú emitiste (customer).

## Esta carpeta no se sube a git

Los XMLs contienen RFCs y datos fiscales — quedan solo en tu máquina (ver `.gitignore`).
