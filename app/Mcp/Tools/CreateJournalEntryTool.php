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

#[Description('Creates a manual journal entry (asiento contable / póliza) — an account.move with move_type=entry — in DRAFT state. Use this for non-invoice operations: payroll informal payments, adjustments, depreciation, opening balances, corrections. Requires at least 2 balanced lines (sum of debits == sum of credits).')]
#[IsOpenWorld(true)]
class CreateJournalEntryTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $journalId = (int) $request->get('journal_id');
            $date = (string) $request->get('date');
            $ref = $request->get('ref');
            $narration = $request->get('narration');
            $lines = $request->get('lines');

            if ($journalId <= 0) {
                return Response::error('journal_id (>0) is required.');
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return Response::error('date must be in YYYY-MM-DD format.');
            }
            if (! is_array($lines) || count($lines) < 2) {
                return Response::error('At least 2 lines are required.');
            }

            $totalDebit = 0.0;
            $totalCredit = 0.0;
            $lineCommands = [];

            foreach ($lines as $i => $line) {
                if (! is_array($line)) {
                    return Response::error("Line #{$i}: must be an object with account_id, debit, credit.");
                }
                $accountId = (int) ($line['account_id'] ?? 0);
                $debit = (float) ($line['debit'] ?? 0);
                $credit = (float) ($line['credit'] ?? 0);
                $name = (string) ($line['name'] ?? '');
                $partnerId = isset($line['partner_id']) ? (int) $line['partner_id'] : null;

                if ($accountId <= 0) {
                    return Response::error("Line #{$i}: account_id is required and must be > 0.");
                }
                if ($debit < 0 || $credit < 0) {
                    return Response::error("Line #{$i}: debit and credit must be >= 0.");
                }
                if ($debit > 0 && $credit > 0) {
                    return Response::error("Line #{$i}: only one of debit/credit can be > 0, not both.");
                }
                if ($debit === 0.0 && $credit === 0.0) {
                    return Response::error("Line #{$i}: at least one of debit/credit must be > 0.");
                }

                $totalDebit += $debit;
                $totalCredit += $credit;

                $vals = [
                    'account_id' => $accountId,
                    'debit' => $debit,
                    'credit' => $credit,
                ];
                if ($name !== '') {
                    $vals['name'] = $name;
                }
                if ($partnerId !== null && $partnerId > 0) {
                    $vals['partner_id'] = $partnerId;
                }
                $lineCommands[] = [0, 0, $vals];
            }

            if (abs($totalDebit - $totalCredit) > 0.005) {
                return Response::error(sprintf(
                    'Unbalanced entry: total debit (%.2f) != total credit (%.2f). Difference: %.2f',
                    $totalDebit,
                    $totalCredit,
                    $totalDebit - $totalCredit
                ));
            }

            $moveVals = [
                'move_type' => 'entry',
                'journal_id' => $journalId,
                'date' => $date,
                'line_ids' => $lineCommands,
            ];
            if ($ref) {
                $moveVals['ref'] = (string) $ref;
            }
            if ($narration) {
                $moveVals['narration'] = (string) $narration;
            }

            $moveId = $odoo->executeKw('account.move', 'create', [$moveVals]);
            if (! is_int($moveId)) {
                return Response::error('account.move.create did not return an integer id.');
            }

            $after = $odoo->executeKw('account.move', 'read', [[$moveId]], [
                'fields' => ['name', 'state', 'journal_id', 'date', 'ref'],
            ]);

            return Response::json([
                'status' => 'created',
                'move_id' => $moveId,
                'move_name' => $after[0]['name'] ?? null,
                'state' => $after[0]['state'] ?? null,
                'journal' => isset($after[0]['journal_id']) && is_array($after[0]['journal_id']) ? $after[0]['journal_id'][1] : null,
                'date' => $after[0]['date'] ?? null,
                'ref' => $after[0]['ref'] ?? null,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'num_lines' => count($lineCommands),
                'next_step' => 'Entry is in DRAFT. Review in Odoo UI and post manually (or via a future post_move tool).',
            ]);
        } catch (Throwable $e) {
            return Response::error('create_journal_entry failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'journal_id' => $schema->integer()
                ->description('Journal id where the entry will be posted (from list_journals). Typically "Miscellaneous Operations" (MISC) for manual adjustments.')
                ->required(),
            'date' => $schema->string()
                ->description('Entry date in YYYY-MM-DD format.')
                ->required(),
            'ref' => $schema->string()
                ->description('Optional reference or folio, e.g. "Nomina abril Q2" or "Ajuste depreciacion".'),
            'narration' => $schema->string()
                ->description('Optional internal note describing the purpose of the entry.'),
            'lines' => $schema->array()
                ->items($schema->object([
                    'account_id' => $schema->integer()
                        ->description('Chart of accounts id (from list_accounts).')
                        ->required(),
                    'name' => $schema->string()
                        ->description('Description of this specific line.'),
                    'debit' => $schema->number()
                        ->description('Debit amount (>= 0). Use 0 if this is a credit line.'),
                    'credit' => $schema->number()
                        ->description('Credit amount (>= 0). Use 0 if this is a debit line.'),
                    'partner_id' => $schema->integer()
                        ->description('Optional partner id for this line (employee, vendor, etc.).'),
                ]))
                ->min(2)
                ->description('At least 2 lines. Sum of all debits MUST equal sum of all credits. Each line must have only one of debit/credit > 0.')
                ->required(),
        ];
    }
}
