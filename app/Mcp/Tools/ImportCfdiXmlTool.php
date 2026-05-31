<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

#[Description('Imports a CFDI 4.0 XML (timbrado en portal SAT) into Odoo as a draft account.move. Parses emisor, receptor, conceptos and amounts; finds-or-creates the counterparty res.partner by RFC; attaches the XML to the move. Use kind=customer when the XML is one you emitted, vendor when it was emitted to you.')]
#[IsOpenWorld(true)]
class ImportCfdiXmlTool extends Tool
{
    private const CFDI_NS = 'http://www.sat.gob.mx/cfd/4';

    private const TFD_NS = 'http://www.sat.gob.mx/TimbreFiscalDigital';

    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $kind = (string) ($request->get('kind') ?? 'customer');
            if (! in_array($kind, ['customer', 'vendor'], true)) {
                return Response::error("kind must be 'customer' or 'vendor'.");
            }

            $xml = $this->loadXmlContent($request);
            $parsed = $this->parseCfdi($xml);

            $counterpart = $kind === 'customer' ? $parsed['receptor'] : $parsed['emisor'];
            $partnerId = $this->findOrCreatePartner($odoo, $counterpart, $kind);

            $ivaTaxId = $this->findIvaTax($odoo, $kind, 16);

            $lines = [];
            foreach ($parsed['conceptos'] as $concepto) {
                $line = [
                    'name' => $concepto['descripcion'],
                    'quantity' => $concepto['cantidad'],
                    'price_unit' => $concepto['valor_unitario'],
                ];
                if (($concepto['descuento'] ?? 0) > 0 && ($concepto['importe'] ?? 0) > 0) {
                    $line['discount'] = round(($concepto['descuento'] / $concepto['importe']) * 100, 6);
                }
                if ($ivaTaxId !== null && $concepto['tiene_iva_16']) {
                    $line['tax_ids'] = [[6, 0, [$ivaTaxId]]];
                }
                $lines[] = [0, 0, $line];
            }

            $moveType = $kind === 'customer' ? 'out_invoice' : 'in_invoice';
            $refParts = array_filter([$parsed['serie'], $parsed['folio']]);
            $ref = $refParts ? implode('-', $refParts) : ($parsed['uuid'] ?: null);

            $moveVals = [
                'move_type' => $moveType,
                'partner_id' => $partnerId,
                'invoice_date' => $parsed['fecha_only'],
                'date' => $parsed['fecha_only'],
                'invoice_line_ids' => $lines,
                'currency_id' => $this->findCurrencyId($odoo, $parsed['moneda']),
            ];
            if ($ref) {
                $moveVals['ref'] = $ref;
            }

            $invoiceId = $odoo->executeKw('account.move', 'create', [$moveVals]);
            if (! is_int($invoiceId)) {
                return Response::error('account.move.create did not return an integer id.');
            }

            $attachmentId = $odoo->executeKw('ir.attachment', 'create', [[
                'name' => 'cfdi.xml',
                'res_model' => 'account.move',
                'res_id' => $invoiceId,
                'type' => 'binary',
                'datas' => base64_encode($xml),
                'mimetype' => 'application/xml',
            ]]);

            $after = $odoo->executeKw('account.move', 'read', [[$invoiceId]], [
                'fields' => ['name', 'state', 'amount_untaxed', 'amount_tax', 'amount_total', 'partner_id'],
            ]);

            $totalsMatch = abs((float) ($after[0]['amount_total'] ?? 0) - (float) $parsed['total']) < 0.5;

            return Response::json([
                'status' => 'imported',
                'invoice_id' => $invoiceId,
                'invoice_state' => $after[0]['state'] ?? null,
                'partner_id' => $partnerId,
                'attachment_id' => is_int($attachmentId) ? $attachmentId : null,
                'parsed' => [
                    'emisor' => $parsed['emisor'],
                    'receptor' => $parsed['receptor'],
                    'fecha' => $parsed['fecha_only'],
                    'serie_folio' => $ref,
                    'uuid' => $parsed['uuid'],
                    'moneda' => $parsed['moneda'],
                    'subtotal' => $parsed['subtotal'],
                    'total' => $parsed['total'],
                    'tiene_retenciones' => $parsed['tiene_retenciones'],
                    'num_conceptos' => count($parsed['conceptos']),
                ],
                'odoo_totals' => [
                    'amount_untaxed' => $after[0]['amount_untaxed'] ?? null,
                    'amount_tax' => $after[0]['amount_tax'] ?? null,
                    'amount_total' => $after[0]['amount_total'] ?? null,
                ],
                'totals_match' => $totalsMatch,
                'warnings' => $this->buildWarnings($parsed, $ivaTaxId, $totalsMatch),
                'next_step' => 'Review the draft invoice in Odoo; if correct, post it (next tool: post_invoice).',
            ]);
        } catch (Throwable $e) {
            return Response::error('import_cfdi_xml failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'xml_path' => $schema->string()
                ->description('Path to the XML file, relative to project root (e.g. "cfdi-inbox/factura.xml"). Either xml_path OR xml_content must be provided.'),
            'xml_content' => $schema->string()
                ->description('Raw XML content as a string. Use when you have the XML inline. Either xml_path OR xml_content must be provided.'),
            'kind' => $schema->string()
                ->description('"customer" if YOU emitted the CFDI (cobras), "vendor" if it was emitted TO YOU (gastas). Default: customer.'),
        ];
    }

    private function loadXmlContent(Request $request): string
    {
        $content = $request->get('xml_content');
        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        $path = $request->get('xml_path');
        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('Provide either xml_path or xml_content.');
        }

        $abs = str_starts_with($path, '/') ? $path : base_path($path);
        if (! is_file($abs)) {
            throw new RuntimeException("XML file not found at: {$abs}");
        }

        $raw = file_get_contents($abs);
        if ($raw === false) {
            throw new RuntimeException("Could not read XML file at: {$abs}");
        }

        return $raw;
    }

    private function parseCfdi(string $xml): array
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            $errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
            throw new RuntimeException('Invalid XML: '.implode(' | ', $errors));
        }

        $doc->registerXPathNamespace('cfdi', self::CFDI_NS);
        $doc->registerXPathNamespace('tfd', self::TFD_NS);

        $attrs = $doc->attributes();
        $fecha = (string) ($attrs['Fecha'] ?? '');
        $subtotal = (float) ($attrs['SubTotal'] ?? 0);
        $total = (float) ($attrs['Total'] ?? 0);
        $serie = (string) ($attrs['Serie'] ?? '');
        $folio = (string) ($attrs['Folio'] ?? '');
        $moneda = (string) ($attrs['Moneda'] ?? 'MXN');

        $emisorNode = $doc->children(self::CFDI_NS)->Emisor;
        $receptorNode = $doc->children(self::CFDI_NS)->Receptor;
        $emisor = [
            'rfc' => (string) ($emisorNode->attributes()['Rfc'] ?? ''),
            'nombre' => (string) ($emisorNode->attributes()['Nombre'] ?? ''),
        ];
        $receptor = [
            'rfc' => (string) ($receptorNode->attributes()['Rfc'] ?? ''),
            'nombre' => (string) ($receptorNode->attributes()['Nombre'] ?? ''),
            'cp' => (string) ($receptorNode->attributes()['DomicilioFiscalReceptor'] ?? ''),
        ];

        $uuid = '';
        $tfd = $doc->xpath('//tfd:TimbreFiscalDigital');
        if ($tfd) {
            $uuid = (string) ($tfd[0]->attributes()['UUID'] ?? '');
        }

        $conceptos = [];
        $tieneRetenciones = false;
        foreach ($doc->xpath('//cfdi:Concepto') ?: [] as $c) {
            $cAttrs = $c->attributes();
            $cantidad = (float) ($cAttrs['Cantidad'] ?? 1);
            $valorUnitario = (float) ($cAttrs['ValorUnitario'] ?? 0);
            $tieneIva16 = false;
            foreach ($c->xpath('.//cfdi:Traslado') ?: [] as $t) {
                $tA = $t->attributes();
                $impuesto = (string) ($tA['Impuesto'] ?? '');
                $tasa = (float) ($tA['TasaOCuota'] ?? 0);
                if ($impuesto === '002' && abs($tasa - 0.16) < 0.0001) {
                    $tieneIva16 = true;
                }
            }
            if ($c->xpath('.//cfdi:Retencion')) {
                $tieneRetenciones = true;
            }
            $importe = (float) ($cAttrs['Importe'] ?? ($cantidad * $valorUnitario));
            $descuento = (float) ($cAttrs['Descuento'] ?? 0);
            $conceptos[] = [
                'descripcion' => (string) ($cAttrs['Descripcion'] ?? 'Concepto'),
                'cantidad' => $cantidad,
                'valor_unitario' => $valorUnitario,
                'importe' => $importe,
                'descuento' => $descuento,
                'tiene_iva_16' => $tieneIva16,
            ];
        }

        return [
            'fecha_raw' => $fecha,
            'fecha_only' => substr($fecha, 0, 10),
            'serie' => $serie,
            'folio' => $folio,
            'subtotal' => $subtotal,
            'total' => $total,
            'moneda' => $moneda,
            'uuid' => $uuid,
            'emisor' => $emisor,
            'receptor' => $receptor,
            'conceptos' => $conceptos,
            'tiene_retenciones' => $tieneRetenciones,
        ];
    }

    private function findOrCreatePartner(OdooClient $odoo, array $info, string $kind): int
    {
        if (! empty($info['rfc'])) {
            $existing = $odoo->executeKw('res.partner', 'search_read', [[['vat', '=', $info['rfc']]]], [
                'fields' => ['id'],
                'limit' => 1,
            ]);
            if (! empty($existing)) {
                return (int) $existing[0]['id'];
            }
        }

        $vals = [
            'name' => $info['nombre'] ?: ($info['rfc'] ?: 'Sin nombre'),
            'is_company' => true,
        ];
        if (! empty($info['rfc'])) {
            $vals['vat'] = $info['rfc'];
        }
        if ($kind === 'customer') {
            $vals['customer_rank'] = 1;
        } else {
            $vals['supplier_rank'] = 1;
        }

        $newId = $odoo->executeKw('res.partner', 'create', [$vals]);
        if (! is_int($newId)) {
            throw new RuntimeException('Could not create res.partner from CFDI counterparty.');
        }

        return $newId;
    }

    private function findIvaTax(OdooClient $odoo, string $kind, float $rate): ?int
    {
        $typeTaxUse = $kind === 'customer' ? 'sale' : 'purchase';
        $taxes = $odoo->executeKw('account.tax', 'search_read', [[
            ['amount', '=', $rate],
            ['type_tax_use', '=', $typeTaxUse],
            ['active', '=', true],
        ]], [
            'fields' => ['id', 'name'],
            'limit' => 1,
        ]);

        return $taxes[0]['id'] ?? null;
    }

    private function findCurrencyId(OdooClient $odoo, string $code): int
    {
        $currencies = $odoo->executeKw('res.currency', 'search_read', [[['name', '=', $code]]], [
            'fields' => ['id'],
            'limit' => 1,
        ]);

        return $currencies[0]['id'] ?? 33;
    }

    private function buildWarnings(array $parsed, ?int $ivaTaxId, bool $totalsMatch): array
    {
        $warnings = [];
        if ($parsed['tiene_retenciones']) {
            $warnings[] = 'CFDI tiene retenciones — no se aplicaron automáticamente en Odoo. Revisa la factura draft y ajusta manualmente si es necesario.';
        }
        if ($ivaTaxId === null) {
            $warnings[] = 'No se encontró impuesto IVA 16% activo en Odoo. La factura quedó sin IVA aplicado — revisa.';
        }
        if (! $totalsMatch) {
            $warnings[] = 'El total calculado por Odoo no coincide con el total del CFDI. Diferencia probable por redondeo de IVA o impuestos no estándar.';
        }

        return $warnings;
    }
}
