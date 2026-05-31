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
use Throwable;

#[Description('Archives a partner / contact (res.partner) by setting active=false. The contact is hidden from default views but not deleted. Reversible via Odoo UI (or future unarchive tool). WRITES to Odoo.')]
#[IsOpenWorld(true)]
#[IsIdempotent(true)]
class ArchivePartnerTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $partnerId = (int) $request->get('partner_id');

            if ($partnerId <= 0) {
                return Response::error('partner_id (>0) is required.');
            }

            $before = $odoo->executeKw('res.partner', 'read', [[$partnerId]], [
                'fields' => ['name', 'email', 'vat', 'active'],
            ]);

            if (empty($before)) {
                return Response::error("Partner id={$partnerId} not found.");
            }

            if ($before[0]['active'] === false) {
                return Response::json([
                    'partner_id' => $partnerId,
                    'name' => $before[0]['name'],
                    'status' => 'already_archived',
                    'note' => 'No action taken; partner was already archived.',
                ]);
            }

            $ok = $odoo->executeKw('res.partner', 'write', [
                [$partnerId],
                ['active' => false],
            ]);

            if (! $ok) {
                return Response::error('write() returned false; no change applied.');
            }

            return Response::json([
                'partner_id' => $partnerId,
                'name' => $before[0]['name'],
                'email' => $before[0]['email'],
                'vat' => $before[0]['vat'],
                'previous_active' => true,
                'new_active' => false,
                'status' => 'archived',
            ]);
        } catch (Throwable $e) {
            return Response::error('archive_partner failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'partner_id' => $schema->integer()
                ->description('ID of the partner to archive (from list_partners).')
                ->required(),
        ];
    }
}
