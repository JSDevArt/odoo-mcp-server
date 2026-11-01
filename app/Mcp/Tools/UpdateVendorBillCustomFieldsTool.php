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

#[Description('Writes values ONLY to custom x_ fields of an existing vendor bill (account.move with move_type in_invoice or in_refund). Works on posted bills; does not reset to draft. WRITES to Odoo.')]
#[IsOpenWorld(true)]
#[IsIdempotent(true)]
class UpdateVendorBillCustomFieldsTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $moveId = (int) $request->get('move_id');
            $values = $request->get('values');
            if ($moveId <= 0 || ! is_array($values) || empty($values)) {
                return Response::error('move_id (>0) and non-empty values object are required.');
            }
            foreach (array_keys($values) as $k) {
                if (! str_starts_with((string) $k, 'x_')) {
                    return Response::error("Only x_ fields can be written; got {$k}.");
                }
            }
            $known = $odoo->executeKw('ir.model.fields', 'search_read', [[['model', '=', 'account.move'], ['name', 'in', array_keys($values)]]], ['fields' => ['name']]);
            $missing = array_diff(array_keys($values), array_column($known, 'name'));
            if ($missing) {
                return Response::error('Unknown fields on account.move: '.implode(', ', $missing));
            }
            $before = $odoo->executeKw('account.move', 'read', [[$moveId]], ['fields' => array_merge(['name', 'move_type'], array_keys($values))]);
            if (empty($before)) {
                return Response::error("Move id={$moveId} not found.");
            }
            if (! in_array($before[0]['move_type'], ['in_invoice', 'in_refund'], true)) {
                return Response::error("Move {$before[0]['name']} is {$before[0]['move_type']}, not a vendor bill.");
            }
            $ok = $odoo->executeKw('account.move', 'write', [[$moveId], $values]);
            if (! $ok) {
                return Response::error('write() returned false; no change applied.');
            }
            $after = $odoo->executeKw('account.move', 'read', [[$moveId]], ['fields' => array_keys($values)]);

            return Response::json([
                'move_id' => $moveId,
                'name' => $before[0]['name'],
                'previous' => array_intersect_key($before[0], $values),
                'current' => array_intersect_key($after[0], $values),
                'status' => 'updated',
            ]);
        } catch (Throwable $e) {
            return Response::error('update_vendor_bill_custom_fields failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'move_id' => $schema->integer()->description('account.move id of the vendor bill.')->required(),
            'values' => $schema->object()->description('Map of x_ field name => value, e.g. {"x_my_flag": false}.')->required(),
        ];
    }
}
