<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Reads all custom (x_) field values of a vendor bill (account.move). Read-only.')]
#[IsReadOnly]
class ReadVendorBillCustomFieldsTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $moveId = (int) $request->get('move_id');
            if ($moveId <= 0) {
                return Response::error('move_id (>0) is required.');
            }
            $fields = $odoo->executeKw('ir.model.fields', 'search_read', [[['model', '=', 'account.move'], ['name', '=like', 'x\_%']]], ['fields' => ['name', 'field_description', 'ttype']]);
            $names = array_column($fields, 'name');
            $rows = $odoo->executeKw('account.move', 'read', [[$moveId]], ['fields' => array_merge(['name', 'move_type', 'state', 'partner_id'], $names)]);
            if (empty($rows)) {
                return Response::error("Move id={$moveId} not found.");
            }
            $r = $rows[0];
            $values = [];
            foreach ($fields as $f) {
                $values[$f['name']] = ['label' => $f['field_description'], 'type' => $f['ttype'], 'value' => $r[$f['name']] ?? null];
            }

            return Response::json([
                'move_id' => $moveId,
                'name' => $r['name'],
                'move_type' => $r['move_type'],
                'state' => $r['state'],
                'partner' => $r['partner_id'],
                'custom_fields' => $values,
            ]);
        } catch (Throwable $e) {
            return Response::error('read_vendor_bill_custom_fields failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'move_id' => $schema->integer()->description('account.move id (from list_invoices).')->required(),
        ];
    }
}
