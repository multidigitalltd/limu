=== crm ===
Contributors: multidigital
Requires at least: 6.6
Requires PHP: 7.4.33
Stable tag: 0.1.12
License: GPLv2 or later

crm

== Installation ==
Install on staging first. Activate and open /crm/ with an administrator account.
Requires MySQL/MariaDB InnoDB transactions and advisory locks; PHP ZipArchive for XLSX.
Configure institution rates, billing start date, duplicate periods and members. Each institution has its own duplicate period; the default is all history, without a year limit. Existing site leads sync automatically. VAT is fixed at 18%.
Exclude /crm/ and /wp-json/limu-crm/v1/* from public page caching.
Managers can apply a shared effective tariff to selected institutions while preserving individual credit terms and rate history.

== Important ==
iCount supports verified client mapping, demands, invoices and payment receipts. Configure server-only LIMU_CRM_ICOUNT_TOKEN and verify the connection before issuance. Emails/SMS are disabled.
Lead capture is automatic from existing site records/dispatch; no per-lead approval. Historical import never bills.
New native leads keep a durable capture marker; accepted AJAX dispatches use a private retry inbox. Transient failures schedule a short retry and retain the hourly WP-Cron fallback without resending leads. Cases requiring manual decisions remain visible to managers. A working WP-Cron or server cron is required.
Full installation, security, adapter and testing notes are in the repository README.md.

Historical mapping repair runs once in WP-Cron batches after update; exact course-parent mapping only, no retroactive billing.
