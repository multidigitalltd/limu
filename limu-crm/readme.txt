=== crm ===
Contributors: multidigital
Requires at least: 6.6
Requires PHP: 7.4.33
Stable tag: 0.1.5
License: GPLv2 or later

crm

== Installation ==
Install on staging first. Activate and open /crm/ with an administrator account.
Requires MySQL/MariaDB InnoDB transactions and advisory locks; PHP ZipArchive for XLSX.
Configure institution rates, billing start date and members. Existing site leads sync automatically. VAT is fixed at 18%.
Exclude /crm/ and /wp-json/limu-crm/v1/* from public page caching.

== Important ==
iCount supports verified client mapping, demands, invoices and payment receipts. Configure server-only LIMU_CRM_ICOUNT_TOKEN and verify the connection before issuance. Emails/SMS are disabled.
Lead capture is automatic from existing site records/dispatch; no per-lead approval. Historical import never bills.
Full installation, security, adapter and testing notes are in the repository README.md.
