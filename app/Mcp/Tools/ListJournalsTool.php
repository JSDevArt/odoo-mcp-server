<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Lists configured accounting journals (account.journal). Use this to see which journals exist (bank, cash, sale, purchase, general). Optional type filter.')]
class ListJournalsTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $type = $request->get('type');

            $domain = [];
            if ($type) {
                $domain[] = ['type', '=', $type];
            }

            $journals = $odoo->executeKw('account.journal', 'search_read', [$domain], [
                'fields' => ['name', 'code', 'type', 'currency_id', 'default_account_id', 'active'],
                'order' => 'type, code',
            ]);

            return Response::json([
                'count' => count($journals),
                'journals' => $journals,
            ]);
        } catch (Throwable $e) {
            return Response::error('list_journals failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description('Optional journal type filter. Valid values: bank, cash, sale, purchase, general.'),
        ];
    }
}
