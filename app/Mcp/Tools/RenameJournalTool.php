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

#[Description('Renames an existing accounting journal (account.journal). Returns previous and new name for verification. WRITES to Odoo.')]
#[IsOpenWorld(true)]
#[IsIdempotent(true)]
class RenameJournalTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $journalId = (int) $request->get('journal_id');
            $newName = (string) $request->get('new_name');

            if ($journalId <= 0 || $newName === '') {
                return Response::error('journal_id (>0) and new_name (non-empty) are required.');
            }

            $before = $odoo->executeKw('account.journal', 'read', [[$journalId]], [
                'fields' => ['name', 'code', 'type'],
            ]);

            if (empty($before)) {
                return Response::error("Journal id={$journalId} not found.");
            }

            $previousName = $before[0]['name'];

            $ok = $odoo->executeKw('account.journal', 'write', [
                [$journalId],
                ['name' => $newName],
            ]);

            if (! $ok) {
                return Response::error('write() returned false; no change applied.');
            }

            return Response::json([
                'journal_id' => $journalId,
                'code' => $before[0]['code'],
                'type' => $before[0]['type'],
                'previous_name' => $previousName,
                'new_name' => $newName,
                'status' => 'renamed',
            ]);
        } catch (Throwable $e) {
            return Response::error('rename_journal failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'journal_id' => $schema->integer()
                ->description('ID of the journal to rename (from list_journals).')
                ->required(),
            'new_name' => $schema->string()
                ->description('New display name for the journal.')
                ->required(),
        ];
    }
}
