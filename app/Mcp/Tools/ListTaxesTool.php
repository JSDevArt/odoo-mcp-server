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

#[Description('Lists taxes (account.tax) configured in Odoo. Optional type_tax_use filter: "sale" (impuestos para facturas de cliente), "purchase" (para gastos), or "all" (default).')]
#[IsReadOnly(true)]
#[IsIdempotent(true)]
#[IsOpenWorld(true)]
class ListTaxesTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $typeTaxUse = (string) ($request->get('type_tax_use') ?? 'all');

            $domain = [];
            if (in_array($typeTaxUse, ['sale', 'purchase'], true)) {
                $domain[] = ['type_tax_use', '=', $typeTaxUse];
            } elseif ($typeTaxUse !== 'all') {
                return Response::error("type_tax_use must be 'sale', 'purchase', or 'all'.");
            }

            $taxes = $odoo->executeKw('account.tax', 'search_read', [$domain], [
                'fields' => ['id', 'name', 'amount', 'type_tax_use', 'active'],
                'order' => 'type_tax_use, amount, name',
            ]);

            return Response::json([
                'count' => count($taxes),
                'filter' => ['type_tax_use' => $typeTaxUse],
                'taxes' => $taxes,
            ]);
        } catch (Throwable $e) {
            return Response::error('list_taxes failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type_tax_use' => $schema->string()
                ->description('Optional filter. Valid values: "sale", "purchase", "all". Default "all".'),
        ];
    }
}
