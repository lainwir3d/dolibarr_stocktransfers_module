# StockTransfers Module Guide

`stocktransfers` (Dolibarr module id 570002) is Spirits Run's fork of the
third-party Imasdeweb "Stock transfers" module (upstream:
https://github.com/caos30/dolibarr_stocktransfers_module, version 1.31,
compatible with Dolibarr 5.x-17.x). Development happens on the branch
`19.0.2_lainwir3d`, which carries Spirits Run fixes on top of merged upstream
history; it depends on the core Product and Stock modules.

Read `../AGENTS.md` in the deployment repository for the general Dolibarr
module guide and the deployment workflow.

## What It Does

- Friendlier warehouse-to-warehouse stock transfer UI than Dolibarr native:
  `transfer_list.php`, `transfer_edit.php`, and `transfer_pdf.php` (a delivery
  note with space for the triple signature of shipper, carrier, and
  receiver).
- `json.php`: JSON endpoints used by the transfer UI.
- Own data table `llx_stocktransfers_transfers`
  (`lib/stocktransfers_transfer.class.php`).
- Trigger `core/triggers/interface_99_all_Stocktransfers.class.php` keeps
  transfer records in sync with stock movements; it auto-detects the
  `custom/` versus `htdocs/` install path at runtime.
- Home box widget (`core/boxes/box_stocktransfers.php`) and config page
  `admin/config.php`.

## Fork Rules

- This is upstream third-party code. Keep changes minimal, self-contained,
  and upstream-mergeable; do not apply Spirits Run conventions or introduce
  dependencies on the alcools/reunionisland modules.
- Spirits Run fixes already on the branch: edit-button icon fix, accented
  character fixes, and removal of an explicit utf-8 encoding request.
- Upstream is merged occasionally through the "base" remote. After merging,
  verify behavior on the pinned Dolibarr 19.0.2 line: upstream targets many
  Dolibarr versions.

## Deployment

Deploys as `stocktransfers/` via `ops/deploy/deploy-module.sh` from the
deployment repository root. The module has no activation-time schema of its
own beyond the `stocktransfers_transfers` table created from its SQL on
first activation.
