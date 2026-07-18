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

#[Description('Updates supplier/customer roles on an existing res.partner by setting supplier_rank/customer_rank. WRITES to Odoo.')]
#[IsOpenWorld(true)]
#[IsIdempotent(true)]
class UpdatePartnerRolesTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $partnerId = (int) $request->get('partner_id');
            if ($partnerId <= 0) {
                return Response::error('partner_id (>0) is required.');
            }

            $before = $odoo->executeKw('res.partner', 'read', [[$partnerId]], [
                'fields' => ['name', 'email', 'vat', 'is_company', 'customer_rank', 'supplier_rank', 'country_id'],
            ]);

            if (empty($before)) {
                return Response::error("Partner id={$partnerId} not found.");
            }

            $vals = [];
            if ($request->get('is_vendor') !== null) {
                $vals['supplier_rank'] = (bool) $request->get('is_vendor') ? max(1, (int) ($before[0]['supplier_rank'] ?? 0)) : 0;
            }
            if ($request->get('is_customer') !== null) {
                $vals['customer_rank'] = (bool) $request->get('is_customer') ? max(1, (int) ($before[0]['customer_rank'] ?? 0)) : 0;
            }

            if ($vals === []) {
                return Response::error('At least one of is_vendor or is_customer must be provided.');
            }

            $ok = $odoo->executeKw('res.partner', 'write', [[$partnerId], $vals]);
            if (! $ok) {
                return Response::error('write() returned false; no change applied.');
            }

            $after = $odoo->executeKw('res.partner', 'read', [[$partnerId]], [
                'fields' => ['name', 'email', 'vat', 'is_company', 'customer_rank', 'supplier_rank', 'country_id'],
            ]);

            return Response::json([
                'status' => 'updated',
                'partner_id' => $partnerId,
                'before' => $before[0],
                'after' => $after[0] ?? null,
            ]);
        } catch (Throwable $e) {
            return Response::error('update_partner_roles failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'partner_id' => $schema->integer()
                ->description('ID of the partner to update (from list_partners).')
                ->required(),
            'is_vendor' => $schema->boolean()
                ->description('Whether this partner should be marked as vendor/supplier/creditor. Optional.'),
            'is_customer' => $schema->boolean()
                ->description('Whether this partner should be marked as customer. Optional.'),
        ];
    }
}
