<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Throwable;

#[Description('Deletes a single payment (account.payment) by id. Cancels it first (action_draft) if posted, then unlinks. Side-effect: any reconciled invoice goes back to "not paid" / "partial". WRITES to Odoo.')]
#[IsOpenWorld(true)]
class DeletePaymentTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $paymentId = (int) $request->get('payment_id');
            if ($paymentId <= 0) {
                return Response::error('payment_id (>0) is required.');
            }

            $payments = $odoo->executeKw('account.payment', 'read', [[$paymentId]], [
                'fields' => ['name', 'date', 'amount', 'partner_id', 'state', 'payment_type', 'journal_id'],
            ]);

            if (empty($payments)) {
                return Response::error("Payment id={$paymentId} not found.");
            }
            $payment = $payments[0];
            $originalState = $payment['state'];

            if (! in_array($originalState, ['draft', 'cancel', 'canceled', 'rejected'], true)) {
                try {
                    $odoo->executeKw('account.payment', 'action_draft', [[$paymentId]]);
                } catch (Throwable $e) {
                    try {
                        $odoo->executeKw('account.payment', 'action_cancel', [[$paymentId]]);
                    } catch (Throwable $e2) {
                        return Response::error(
                            "Could not bring payment id={$paymentId} to a deletable state. ".
                            'action_draft failed: '.$e->getMessage().
                            ' | action_cancel failed: '.$e2->getMessage()
                        );
                    }
                }
            }

            $ok = $odoo->executeKw('account.payment', 'unlink', [[$paymentId]]);
            if (! $ok) {
                return Response::error('unlink() returned false; no deletion applied.');
            }

            return Response::json([
                'status' => 'deleted',
                'payment_id' => $paymentId,
                'previous_name' => $payment['name'],
                'previous_state' => $originalState,
                'amount' => $payment['amount'],
                'partner' => is_array($payment['partner_id']) ? $payment['partner_id'][1] : null,
                'journal' => is_array($payment['journal_id']) ? $payment['journal_id'][1] : null,
                'note' => 'If this payment was reconciled with an invoice, that invoice now has higher amount_residual (less paid).',
            ]);
        } catch (Throwable $e) {
            return Response::error('delete_payment failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'payment_id' => $schema->integer()
                ->description('ID of the payment to delete (from list_payments).')
                ->required(),
        ];
    }
}
