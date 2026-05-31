<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Lists installed Odoo modules (ir.module.module where state=installed). Optional query filters by technical name or display name.')]
class ListInstalledModulesTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $query = $request->get('query');

            $domain = [['state', '=', 'installed']];
            if ($query) {
                $domain[] = '|';
                $domain[] = ['name', 'ilike', $query];
                $domain[] = ['shortdesc', 'ilike', $query];
            }

            $modules = $odoo->executeKw('ir.module.module', 'search_read', [$domain], [
                'fields' => ['name', 'shortdesc', 'application'],
                'order' => 'application desc, name asc',
                'limit' => 200,
            ]);

            return Response::json([
                'count' => count($modules),
                'modules' => $modules,
            ]);
        } catch (Throwable $e) {
            return Response::error('list_installed_modules failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Optional text to filter modules by technical name or display name.'),
        ];
    }
}
