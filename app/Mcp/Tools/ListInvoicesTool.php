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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Lists invoices (account.move filtered to customer/vendor invoices and refunds). Optional filters: kind (customer/vendor/all), state (draft/posted/cancel), partner_id, product_id (matches if any line uses that product), limit.')]
#[IsReadOnly(true)]
#[IsIdempotent(true)]
#[IsOpenWorld(true)]
class ListInvoicesTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $kind = (string) ($request->get('kind') ?? 'customer');
            $state = $request->get('state');
            $partnerId = $request->get('partner_id');
            $productId = $request->get('product_id');
            $limit = (int) ($request->get('limit') ?? 20);

            $typeMap = [
                'customer' => ['out_invoice', 'out_refund'],
                'vendor' => ['in_invoice', 'in_refund'],
                'all' => ['out_invoice', 'out_refund', 'in_invoice', 'in_refund'],
            ];
            $moveTypes = $typeMap[$kind] ?? $typeMap['customer'];

            $domain = [['move_type', 'in', $moveTypes]];

            if ($state) {
                $domain[] = ['state', '=', $state];
            }
            if ($partnerId) {
                $domain[] = ['partner_id', '=', (int) $partnerId];
            }
            if ($productId) {
                $domain[] = ['invoice_line_ids.product_id', '=', (int) $productId];
            }

            $invoices = $odoo->executeKw('account.move', 'search_read', [$domain], [
                'fields' => ['name', 'partner_id', 'invoice_date', 'invoice_date_due', 'amount_total', 'amount_residual', 'state', 'move_type', 'ref', 'currency_id'],
                'order' => 'invoice_date desc, id desc',
                'limit' => $limit,
            ]);

            return Response::json([
                'count' => count($invoices),
                'limit' => $limit,
                'filters' => [
                    'kind' => $kind,
                    'state' => $state,
                    'partner_id' => $partnerId,
                    'product_id' => $productId,
                ],
                'invoices' => $invoices,
            ]);
        } catch (Throwable $e) {
            return Response::error('list_invoices failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()
                ->description('Invoice kind: "customer" (out_invoice/out_refund), "vendor" (in_invoice/in_refund), or "all". Default "customer".'),
            'state' => $schema->string()
                ->description('Optional state filter: draft, posted, cancel.'),
            'partner_id' => $schema->integer()
                ->description('Optional partner filter (customer or vendor id).'),
            'product_id' => $schema->integer()
                ->description('Optional product filter: returns invoices where any line uses this product.'),
            'limit' => $schema->integer()
                ->description('Max results. Default 20.'),
        ];
    }
}
