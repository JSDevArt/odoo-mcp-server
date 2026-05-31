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

#[Description('Lists payments (account.payment): cobros and pagos. Useful to find IDs of duplicate or extra payments. Optional filters: partner_id, kind (inbound/outbound), state, limit.')]
#[IsReadOnly(true)]
#[IsIdempotent(true)]
#[IsOpenWorld(true)]
class ListPaymentsTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $partnerId = $request->get('partner_id');
            $kind = $request->get('kind');
            $state = $request->get('state');
            $limit = (int) ($request->get('limit') ?? 30);

            $domain = [];
            if ($partnerId) {
                $domain[] = ['partner_id', '=', (int) $partnerId];
            }
            if ($kind === 'inbound') {
                $domain[] = ['payment_type', '=', 'inbound'];
            } elseif ($kind === 'outbound') {
                $domain[] = ['payment_type', '=', 'outbound'];
            }
            if ($state) {
                $domain[] = ['state', '=', $state];
            }

            $payments = $odoo->executeKw('account.payment', 'search_read', [$domain], [
                'fields' => ['name', 'date', 'amount', 'partner_id', 'journal_id', 'state', 'payment_type', 'reconciled_invoice_ids'],
                'order' => 'date desc, id desc',
                'limit' => $limit,
            ]);

            return Response::json([
                'count' => count($payments),
                'limit' => $limit,
                'filters' => [
                    'partner_id' => $partnerId,
                    'kind' => $kind,
                    'state' => $state,
                ],
                'payments' => $payments,
            ]);
        } catch (Throwable $e) {
            return Response::error('list_payments failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'partner_id' => $schema->integer()
                ->description('Optional partner filter (customer or vendor id).'),
            'kind' => $schema->string()
                ->description('Optional: "inbound" (cobros) or "outbound" (pagos a proveedores).'),
            'state' => $schema->string()
                ->description('Optional state filter: draft, in_process, paid, canceled.'),
            'limit' => $schema->integer()
                ->description('Max results. Default 30.'),
        ];
    }
}
