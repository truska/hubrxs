<?php
require_once __DIR__ . '/auth.php';

function hub_import_sales_order_file(string $filePath, string $originalName = null, ?int $uploadedBy = null): array {
  return [
    'ok' => false,
    'message' => 'Legacy uploaded sales order import has been retired. Use the remote SO Portal Lines import, which writes to hub_so_raw, hub_so_processed, and hub_so_live.',
    'rows' => 0,
  ];
}