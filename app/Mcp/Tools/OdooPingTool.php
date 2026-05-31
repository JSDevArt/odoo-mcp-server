<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Verifies the Odoo connection by authenticating and reading the current user (res.users). Returns uid, name, login, and company.')]
class OdooPingTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $uid = $odoo->authenticate();
            $users = $odoo->executeKw('res.users', 'read', [[$uid]], [
                'fields' => ['name', 'login', 'company_id', 'lang', 'tz'],
            ]);
            $user = $users[0] ?? null;

            if (! $user) {
                return Response::text('Auth OK (uid='.$uid.') but could not read res.users.');
            }

            return Response::json([
                'uid' => $user['id'],
                'name' => $user['name'],
                'login' => $user['login'],
                'company' => is_array($user['company_id']) ? $user['company_id'][1] : null,
                'lang' => $user['lang'],
                'tz' => $user['tz'],
            ]);
        } catch (Throwable $e) {
            return Response::error('Odoo ping failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
