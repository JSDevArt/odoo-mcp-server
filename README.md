<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Odoo MCP Server

Este proyecto expone un servidor MCP (Model Context Protocol) que conecta Claude con una instancia de Odoo SaaS vía JSON-RPC. Claude puede consultar y operar sobre contabilidad (facturas, pagos, socios, pólizas, etc.) directamente desde la conversación.

### Requisitos previos

- Docker y Docker Compose instalados
- Acceso a una instancia de Odoo (plan que incluya API externa)

### Levantar el servidor

```bash
# 1. Clona el repositorio
git clone https://github.com/tu-usuario/odoo-mcp.git
cd odoo-mcp

# 2. Configura las variables de entorno
cp .env.example .env
# Edita .env y rellena ODOO_URL, ODOO_DB, ODOO_LOGIN, ODOO_API_KEY

# 3. Levanta los contenedores
docker compose up -d --build
```

El servidor queda disponible en `http://tu-servidor/mcp/odoo`.

### Conectar Claude Code al servidor

Copia `.mcp.json.example` a `.mcp.json` y reemplaza la IP:

```bash
cp .mcp.json.example .mcp.json
# Edita .mcp.json y pon la IP o dominio de tu servidor
```

```json
{
  "mcpServers": {
    "odoo": {
      "type": "http",
      "url": "http://tu-servidor/mcp/odoo"
    }
  }
}
```

> `.mcp.json` está en `.gitignore` — cada usuario configura su propia URL sin pisar la de otros.

### Verificar la conexión a Odoo

```bash
bash scripts/verify.sh
```

El script autentica contra la API de Odoo e imprime los datos del usuario. Si todo va bien verás `name`, `login` y `company_id` en la respuesta.

### Herramientas disponibles

| Herramienta | Tipo | Descripción |
|---|---|---|
| `odoo_ping` | lectura | Verifica la conexión |
| `get_company_info` | lectura | Info de la empresa activa |
| `list_journals` | lectura | Lista diarios contables |
| `list_accounts` | lectura | Plan de cuentas |
| `list_partners` | lectura | Clientes y proveedores |
| `list_products` | lectura | Catálogo de productos |
| `list_invoices` | lectura | Facturas de venta/compra |
| `list_payments` | lectura | Pagos registrados |
| `list_taxes` | lectura | Impuestos configurados |
| `list_installed_modules` | lectura | Módulos instalados |
| `create_partner` | escritura | Crea cliente o proveedor |
| `rename_partner` | escritura | Renombra un partner |
| `archive_partner` | escritura | Archiva un partner |
| `create_vendor_bill` | escritura | Crea factura de proveedor |
| `update_invoice_line` | escritura | Edita línea de factura |
| `import_cfdi_xml` | escritura | Importa CFDI desde XML |
| `register_payment` | escritura | Registra pago de una factura |
| `delete_invoice` | escritura | Elimina factura en borrador |
| `delete_payment` | escritura | Elimina pago |
| `create_journal_entry` | escritura | Crea asiento manual (póliza) |
| `post_move` | escritura | Publica un asiento borrador |
| `rename_journal` | escritura | Renombra un diario |

> Las herramientas de **escritura** mutan datos en Odoo — confirma siempre la operación antes de ejecutarlas.

---

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
