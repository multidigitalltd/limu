=== Limu CRM — Multi Digital ===
Contributors: multidigital
Requires at least: 6.6
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later

Hebrew private CRM portal for institution leads and monthly internal billing.

== Installation ==
Install on staging first. Activate and open /crm/ with an administrator account.
Requires MySQL/MariaDB InnoDB transactions and advisory locks; PHP ZipArchive for XLSX.
Configure institution rates, billing start date, members and Elementor form mappings.
Exclude /crm/ and /wp-json/limu-crm/v1/* from public page caching.

== Important ==
iCount integration is reserved for the final phase. No invoices or external emails are sent.
Elementor capture is pending until actual delivery is verified. Historical import never bills.
Full installation, security, adapter and testing notes are in the repository README.md.
