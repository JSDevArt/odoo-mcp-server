<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Lists products / services (product.product). Optional query filters by name or default code (SKU).')]
class ListProductsTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $query = $request->get('query');
            $limit = (int) ($request->get('limit') ?? 50);

            $domain = [];
            if ($query) {
                $domain[] = '|';
                $domain[] = ['name', 'ilike', $query];
                $domain[] = ['default_code', 'ilike', $query];
            }

            $products = $odoo->executeKw('product.product', 'search_read', [$domain], [
                'fields' => ['name', 'default_code', 'type', 'list_price', 'standard_price', 'sale_ok', 'purchase_ok'],
                'order' => 'name',
                'limit' => $limit,
            ]);

            return Response::json([
                'count' => count($products),
                'limit' => $limit,
                'products' => $products,
            ]);
        } catch (Throwable $e) {
            return Response::error('list_products failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Optional text to filter by product name or default_code (SKU).'),
            'limit' => $schema->integer()
                ->description('Max results. Default 50.'),
        ];
    }
}
