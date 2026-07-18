<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ArchivePartnerTool;
use App\Mcp\Tools\AttachFileTool;
use App\Mcp\Tools\CreateJournalEntryTool;
use App\Mcp\Tools\CreatePartnerTool;
use App\Mcp\Tools\CreateVendorBillTool;
use App\Mcp\Tools\DeleteInvoiceTool;
use App\Mcp\Tools\DeletePaymentTool;
use App\Mcp\Tools\ForceDeleteInvoiceTool;
use App\Mcp\Tools\GetCompanyInfoTool;
use App\Mcp\Tools\ImportCfdiXmlTool;
use App\Mcp\Tools\ListAccountsTool;
use App\Mcp\Tools\ListInstalledModulesTool;
use App\Mcp\Tools\ListInvoicesTool;
use App\Mcp\Tools\ListJournalsTool;
use App\Mcp\Tools\ListPartnersTool;
use App\Mcp\Tools\ListPaymentsTool;
use App\Mcp\Tools\ListProductsTool;
use App\Mcp\Tools\ListTaxesTool;
use App\Mcp\Tools\OdooPingTool;
use App\Mcp\Tools\PostMoveTool;
use App\Mcp\Tools\RegisterPaymentTool;
use App\Mcp\Tools\RenameJournalTool;
use App\Mcp\Tools\ReclassifyVendorBillToCreditorTool;
use App\Mcp\Tools\RenamePartnerTool;
use App\Mcp\Tools\UpdateInvoiceLineTool;
use App\Mcp\Tools\UpdatePartnerRolesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Odoo MCP')]
#[Version('0.3.0')]
#[Instructions(<<<'TXT'
Bridges Claude with an Odoo SaaS instance via JSON-RPC for JSDevArt accounting.

Connection check: `odoo_ping`.
Read tools (safe, no writes): list_installed_modules, list_journals, list_accounts, list_partners, list_products.
Write tools (MUTATE Odoo, confirm with user before invoking): rename_journal.

More write tools (archive_partner, create_customer_invoice, register_payment, etc.) will be added incrementally.
TXT)]
class OdooServer extends Server
{
    protected array $tools = [
        OdooPingTool::class,
        GetCompanyInfoTool::class,
        ListInstalledModulesTool::class,
        ListJournalsTool::class,
        ListAccountsTool::class,
        ListPartnersTool::class,
        ListProductsTool::class,
        ListInvoicesTool::class,
        ListPaymentsTool::class,
        ListTaxesTool::class,
        RenameJournalTool::class,
        ArchivePartnerTool::class,
        RenamePartnerTool::class,
        RegisterPaymentTool::class,
        DeleteInvoiceTool::class,
        DeletePaymentTool::class,
        ForceDeleteInvoiceTool::class,
        AttachFileTool::class,
        ImportCfdiXmlTool::class,
        CreateJournalEntryTool::class,
        ReclassifyVendorBillToCreditorTool::class,
        PostMoveTool::class,
        CreatePartnerTool::class,
        CreateVendorBillTool::class,
        UpdateInvoiceLineTool::class,
        UpdatePartnerRolesTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
