<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Reads the active Odoo company (res.company) including its linked partner_id, currency, RFC (vat), and address. Useful to identify which res.partner record represents the company.')]
#[IsReadOnly(true)]
#[IsIdempotent(true)]
#[IsOpenWorld(true)]
class GetCompanyInfoTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $companies = $odoo->executeKw('res.company', 'search_read', [[]], [
                'fields' => ['id', 'name', 'partner_id', 'currency_id', 'vat', 'street', 'city', 'zip', 'country_id', 'state_id', 'phone', 'email'],
            ]);

            return Response::json([
                'count' => count($companies),
                'companies' => $companies,
            ]);
        } catch (Throwable $e) {
            return Response::error('get_company_info failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
