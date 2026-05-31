<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Lists chart-of-accounts entries (account.account). Optional query filters by code or name. Default limit is 50.')]
class ListAccountsTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $query = $request->get('query');
            $limit = (int) ($request->get('limit') ?? 50);

            $domain = [];
            if ($query) {
                $domain[] = '|';
                $domain[] = ['code', 'ilike', $query];
                $domain[] = ['name', 'ilike', $query];
            }

            $accounts = $odoo->executeKw('account.account', 'search_read', [$domain], [
                'fields' => ['code', 'name', 'account_type', 'reconcile'],
                'order' => 'code',
                'limit' => $limit,
            ]);

            return Response::json([
                'count' => count($accounts),
                'limit' => $limit,
                'accounts' => $accounts,
            ]);
        } catch (Throwable $e) {
            return Response::error('list_accounts failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Optional text to filter accounts by code or name.'),
            'limit' => $schema->integer()
                ->description('Max results to return. Default 50.'),
        ];
    }
}
