# Accounting Model

This document describes how to use this MCP server with Odoo for accounting operations.

## Hybrid CFDI model

The recommended setup for Mexican businesses:

| Aspect | Where |
|---|---|
| Issue CFDI (timbrar) | SAT portal (manual, free) or a PAC |
| Store invoice as accounting record | Odoo (`account.move`) |
| CFDI XML | Attached to the `account.move` in Odoo |
| Accounting / journal entries | Odoo |
| Reports (P&L, balance sheet) | Odoo |

## Odoo objects

| Business concept | Odoo model | Key fields |
|---|---|---|
| Customer invoice | `account.move` (`move_type='out_invoice'`) | `partner_id`, `invoice_date`, `invoice_line_ids` |
| Vendor bill | `account.move` (`move_type='in_invoice'`) | same + `ref` (vendor reference) |
| Manual journal entry | `account.move` (`move_type='entry'`) | `journal_id`, `line_ids` (balanced Debit/Credit) |
| Payment | `account.payment` | `partner_id`, `amount`, `journal_id`, `payment_type` |
| Customer / vendor | `res.partner` | `name`, `vat` (RFC), `customer_rank`, `supplier_rank` |
| Account | `account.account` | `code`, `name`, `account_type` |
| Journal | `account.journal` | `code`, `name`, `type` |
| Product / service | `product.product` | `name`, `type`, `lst_price`, `taxes_id` |
| Tax | `account.tax` | `name`, `amount`, `type_tax_use` |

## Common accounting operations

### 1. Customer invoice with CFDI
1. Issue CFDI on the SAT portal.
2. Download the XML and place it in `cfdi-inbox/`.
3. Use `import_cfdi_xml` tool → creates a draft `account.move`.
4. Review and post in Odoo.
5. Register payment with `register_payment` when collected.

### 2. Customer invoice without CFDI (B2C)
1. Create invoice directly in Odoo (no timbrado needed).
2. Post the invoice.
3. Register payment when collected.

### 3. Vendor bill with CFDI
1. Receive the vendor's XML.
2. Use `import_cfdi_xml(kind="vendor")` → creates a draft vendor bill.
3. Post and register payment when paid.

### 4. Vendor bill without CFDI (international apps, etc.)
1. Use `create_vendor_bill` tool.
2. Attach the receipt/PDF.
3. Post and register payment.

### 5. Manual journal entry (capital injection, bank transfers, adjustments)
1. Use `create_journal_entry` tool with balanced debit/credit lines.
2. Use `post_move` to publish the draft entry.

## Required Odoo modules

- `accounting` or `accountant` — core accounting
- `l10n_mx` — Mexican chart of accounts, SAT taxes, RFC on partners
- `l10n_mx_reports` — SAT reports (DIOT, CODI, etc.)
- `l10n_mx_edi` — only needed if using a PAC for automated timbrado
