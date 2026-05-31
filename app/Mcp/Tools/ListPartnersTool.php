<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Lists partners / contacts (res.partner): customers, suppliers, employees. Optional query filters by name, email, or VAT (RFC). Optional kind filter.')]
class ListPartnersTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $query = $request->get('query');
            $kind = $request->get('kind');
            $limit = (int) ($request->get('limit') ?? 50);

            $domain = [];
            if ($kind === 'customer') {
                $domain[] = ['customer_rank', '>', 0];
            } elseif ($kind === 'supplier') {
                $domain[] = ['supplier_rank', '>', 0];
            }
            if ($query) {
                $domain[] = '|';
                $domain[] = '|';
                $domain[] = ['name', 'ilike', $query];
                $domain[] = ['email', 'ilike', $query];
                $domain[] = ['vat', 'ilike', $query];
            }

            $partners = $odoo->executeKw('res.partner', 'search_read', [$domain], [
                'fields' => ['name', 'email', 'vat', 'is_company', 'customer_rank', 'supplier_rank', 'country_id'],
                'order' => 'name',
                'limit' => $limit,
            ]);

            return Response::json([
                'count' => count($partners),
                'limit' => $limit,
                'partners' => $partners,
            ]);
        } catch (Throwable $e) {
            return Response::error('list_partners failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Optional text to filter by name, email, or RFC (vat).'),
            'kind' => $schema->string()
                ->description('Optional filter: "customer" (customer_rank>0), "supplier" (supplier_rank>0), or omit for all.'),
            'limit' => $schema->integer()
                ->description('Max results. Default 50.'),
        ];
    }
}
