<?php

use App\Mcp\Servers\OdooServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/odoo', OdooServer::class);
