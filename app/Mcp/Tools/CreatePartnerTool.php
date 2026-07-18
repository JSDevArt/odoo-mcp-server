<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Throwable;

#[Description('Creates a res.partner (contact) in Odoo. Use this to register a new vendor or customer. Defaults to is_company=true and is_vendor=true. WRITES to Odoo.')]
#[IsOpenWorld(true)]
class CreatePartnerTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $name = trim((string) $request->get('name'));
            if ($name === '') {
                return Response::error('name is required and must be non-empty.');
            }

            $vat = $request->get('vat');
            $countryCode = $request->get('country_code');
            $isVendor = $request->get('is_vendor');
            $isCustomer = $request->get('is_customer');
            $isCompany = $request->get('is_company');

            $isVendor = $isVendor === null ? true : (bool) $isVendor;
            $isCustomer = $isCustomer === null ? false : (bool) $isCustomer;
            $isCompany = $isCompany === null ? true : (bool) $isCompany;

            $vals = [
                'name' => $name,
                'is_company' => $isCompany,
                'supplier_rank' => $isVendor ? 1 : 0,
                'customer_rank' => $isCustomer ? 1 : 0,
            ];

            if (is_string($vat) && trim($vat) !== '') {
                $vals['vat'] = trim($vat);
            }

            if (is_string($countryCode) && trim($countryCode) !== '') {
                $countries = $odoo->executeKw('res.country', 'search_read', [[
                    ['code', '=', strtoupper(trim($countryCode))],
                ]], [
                    'fields' => ['id', 'name'],
                    'limit' => 1,
                ]);
                if (empty($countries)) {
                    return Response::error("Country code '{$countryCode}' not found in Odoo (expected ISO-2 like 'MX', 'US').");
                }
                $vals['country_id'] = $countries[0]['id'];
            }

            $partnerId = $odoo->executeKw('res.partner', 'create', [$vals]);
            if (! is_int($partnerId)) {
                return Response::error('res.partner.create did not return an integer id.');
            }

            $after = $odoo->executeKw('res.partner', 'read', [[$partnerId]], [
                'fields' => ['name', 'vat', 'is_company', 'customer_rank', 'supplier_rank', 'country_id'],
            ]);

            return Response::json([
                'status' => 'created',
                'partner_id' => $partnerId,
                'name' => $after[0]['name'] ?? $name,
                'vat' => $after[0]['vat'] ?? null,
                'is_company' => $after[0]['is_company'] ?? true,
                'is_vendor' => ($after[0]['supplier_rank'] ?? 0) > 0,
                'is_customer' => ($after[0]['customer_rank'] ?? 0) > 0,
                'country' => isset($after[0]['country_id']) && is_array($after[0]['country_id']) ? $after[0]['country_id'][1] : null,
            ]);
        } catch (Throwable $e) {
            return Response::error('create_partner failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Display name of the partner (e.g. "Hosting Co, S.A. de C.V." or "Netflix").')
                ->required(),
            'vat' => $schema->string()
                ->description('Optional VAT / RFC identifier. For Mexican partners use the 12-13 char RFC.'),
            'country_code' => $schema->string()
                ->description('Optional ISO-2 country code, e.g. "MX", "US", "ES".'),
            'is_vendor' => $schema->boolean()
                ->description('Whether this partner is a vendor / supplier. Defaults to true.'),
            'is_customer' => $schema->boolean()
                ->description('Whether this partner is a customer. Defaults to false.'),
            'is_company' => $schema->boolean()
                ->description('Whether this partner is a company. Defaults to true. Set false for individuals / natural persons.'),
        ];
    }
}
