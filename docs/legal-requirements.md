<!--
	SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
	SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Legal requirements for a digital cashbook (Germany)

This document lists the requirements that German tax and commercial law places on an electronic cashbook (*Kassenbuch*).
It is the reference for features and design decisions of this app. Each requirement has a stable ID, so tickets and pull requests can refer to it.

> [!IMPORTANT]
> This is a technical summary, not legal or tax advice.
> Whether a business keeps its books properly is always the responsibility of the taxpayer and should be confirmed by a tax advisor.

The keywords **MUST** and **SHOULD** are used as in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119):
**MUST** follows directly from the law or the GoBD, **SHOULD** is established practice that tax auditors expect or that avoids formal defects.

## Legal sources

| Source | Relevance |
| --- | --- |
| **AO** (*Abgabenordnung*, Fiscal Code) § 146 | General recording rules: individual, complete, correct, timely and orderly records; cash receipts and payments are recorded **daily**; immutability; records in a living language. |
| AO § 146a, **KassenSichV** | Technical security device (TSE), receipt obligation and registration for electronic cash registers. See [Out of scope](#out-of-scope). |
| AO § 146b | *Kassen-Nachschau*: unannounced inspection of the cash records by the tax office. |
| AO § 147 | Retention periods and formats, data access by the tax authority (Z1/Z2/Z3). |
| AO §§ 158, 162 | Records are only presumed correct if they are formally correct; otherwise the tax office may estimate the income (*Hinzuschätzung*). |
| AO § 379 | Improper records are an administrative offence. |
| AO § 87 | German is the official language; translations of foreign-language documents can be requested. |
| **HGB** (*Handelsgesetzbuch*, Commercial Code) §§ 238, 239, 257 | The same principles for merchants obliged to keep double-entry books; retention. |
| **UStG** § 22, **UStDV** §§ 63 ff. | VAT records: amounts separated by tax rate. |
| EStG § 4 Abs. 3 | Cash-basis accounting (*Einnahmenüberschussrechnung*, EÜR). |
| **GoBD** (BMF letter of 28 November 2019, later amended regarding e-invoices) | Administrative guidance on how IT systems must implement the principles above. Chapter references below refer to this letter. |
| **GDPR** Art. 17 Abs. 3 lit. b | The statutory retention duty overrides the right to erasure. |

## Glossary

| German | English (as used in this document) |
| --- | --- |
| Kassenbuch | cashbook |
| Kassenbestand (Soll / Ist) | cash balance (book / counted) |
| Kassensturz | cash count, comparing the book balance with the counted cash |
| Kassensturzfähigkeit | the ability to run a cash count at any time |
| Zählprotokoll | cash count protocol (count per denomination) |
| Kassenbericht | daily cash report of an open till without individual sales records |
| offene Ladenkasse | open till: cash drawer without an electronic register |
| Beleg / Eigenbeleg | receipt / self-issued receipt |
| Storno / Stornobuchung | cancellation / reversing entry |
| Stammdaten | master data (cashbook name, account, currency, …) |
| Verfahrensdokumentation | procedural documentation |
| Aufbewahrungsfrist | retention period |
| Datenzugriff (Z1, Z2, Z3) | data access by the tax authority (direct, indirect, data carrier) |
| Betriebsprüfung | tax audit |

## Applicability

The requirements apply to everyone who keeps a cashbook, with these differences:

- **Merchants with double-entry bookkeeping** (HGB, AO § 141): the cashbook is part of the books. The cash balance must be verifiable by a cash count at any time.
- **Cash-basis accounting** (EÜR): a cashbook in the strict sense is not mandatory, but cash receipts and payments still have to be recorded individually. In practice auditors expect a cashbook, and the same rules are applied to it.
- **Non-profit associations** must keep proper records (AO § 63 Abs. 3). The requirements are the same.

The app is meant for all of these users, so it implements the strictest variant.

## Requirements

### Recording (REC)

| ID | Requirement | Basis |
| --- | --- | --- |
| REC-1 | Every cash transaction **MUST** be recorded **individually**. Combined entries are only allowed for the sale of goods to many unknown persons against cash, and only without an electronic cash register. | AO § 146 Abs. 1; GoBD 3.2.1 |
| REC-2 | All cash movements **MUST** be recorded, including owner withdrawals and deposits, transfers between bank and till, change money, tips and refunds. | AO § 146 Abs. 1; GoBD 3.2.1 |
| REC-3 | Cash receipts and payments **MUST** be recorded **daily**. | AO § 146 Abs. 1 S. 2; GoBD 3.2.3 |
| REC-4 | Every entry **MUST** store the **date of the transaction** and the **time of recording** separately, so that late records are recognizable. | AO § 146 Abs. 4; GoBD 3.2.3, 5.3 |
| REC-5 | Entries **MUST** be in chronological order (journal) and **SHOULD** carry a **gapless sequence number per cashbook**. Gaps have to be explainable. | GoBD 3.2.4, 5.3 |
| REC-6 | Every entry **MUST** contain at least: sequence number, transaction date, recording time, amount, currency, posting text, reason (category / contra account), VAT rate, receipt reference, and the user who recorded it. | GoBD 5.3 |
| REC-7 | Day boundaries **MUST** follow the local business day of the cashbook, not UTC. The time zone of a cashbook **SHOULD** be stored with it. | AO § 146 Abs. 1 S. 2 |
| REC-8 | Codes, abbreviations and symbols (e.g. reason codes) **MUST** have a documented, unambiguous meaning. A change of meaning **MUST** be versioned. | AO § 146 Abs. 3 S. 3 |

### Balance and cash count (BAL)

| ID | Requirement | Basis |
| --- | --- | --- |
| BAL-1 | The balance **MUST** follow from the opening balance and all movements without gaps. It **SHOULD** be computed and verified by the system, not taken from user input. | GoBD 3.2.2, 6 |
| BAL-2 | The cash balance **MUST NOT** become **negative**. A negative balance is a formal defect and a strong indication of unrecorded income. | AO §§ 158, 162 |
| BAL-3 | It **MUST** be possible to compare the book balance with the counted cash at any time (*Kassensturzfähigkeit*). | AO § 146 Abs. 1; HGB § 239 |
| BAL-4 | A daily closing **SHOULD** record a cash count protocol (count per denomination) and the difference between book and counted balance. A difference **MUST** be recorded as its own entry. | GoBD 3.2.2, 6 |
| BAL-5 | For an open till without individual sales records, the daily takings **MUST** be derived by a daily cash report: counted closing balance − opening balance + payments + withdrawals − deposits = takings. | AO § 146 Abs. 1 S. 3 |
| BAL-6 | After the daily closing, the entries of that day **SHOULD** be locked (*Festschreibung*). | GoBD 3.2.5, 8 |

### Immutability and corrections (IMM)

| ID | Requirement | Basis |
| --- | --- | --- |
| IMM-1 | Recorded entries **MUST NOT** be changed or deleted in a way that hides their original content. For a cashbook this applies from the moment of recording, or at the latest from the daily closing. | AO § 146 Abs. 4; HGB § 239 Abs. 3; GoBD 3.2.5, 8 |
| IMM-2 | A correction **MUST** be recorded as a **reversing entry** that references the original entry, followed by a new correct entry if needed. | GoBD 3.2.5, 8 |
| IMM-3 | It **MUST** be recognizable whether a record is original or was added later (see REC-4). | AO § 146 Abs. 4 S. 2 |
| IMM-4 | Immutability can be ensured by hardware, software or organizational measures. A spreadsheet-like editable store is not sufficient. | GoBD 8 |
| IMM-5 | The system **SHOULD** make manipulation outside the application (e.g. direct database edits) detectable, for example by chaining a hash of each entry with the hash of its predecessor. | GoBD 8 |

### Audit trail (LOG)

| ID | Requirement | Basis |
| --- | --- | --- |
| LOG-1 | Changes to **master data** that affect the meaning of entries (cashbook name, contra account, currency, reason codes, tax rates) **MUST** be logged with old value, new value, user and time. | GoBD 8 |
| LOG-2 | Logs **MUST** be retained like the entries they explain. | AO § 147 Abs. 1 Nr. 1 |
| LOG-3 | Imports, exports, daily closings and access grants **SHOULD** be logged. | GoBD 6, 8 |

### Receipts (DOC)

| ID | Requirement | Basis |
| --- | --- | --- |
| DOC-1 | Every entry **MUST** be backed by a receipt ("no entry without a receipt"). Without an external receipt, a self-issued receipt (date, amount, purpose, reason why no receipt exists, signature) is required. | GoBD 4 |
| DOC-2 | Receipt and entry **MUST** be linked unambiguously in both directions. | GoBD 4 |
| DOC-3 | Digital receipts **MUST** be stored unchangeable and in their original format. Structured e-invoices (XRechnung, ZUGFeRD) **MUST** keep their XML data. | AO § 147 Abs. 2; GoBD 4, 9 |
| DOC-4 | A plain link to a file the user can still edit or delete (e.g. a file in Nextcloud Files) is **not** sufficient. The receipt **SHOULD** be copied into storage controlled by the app and protected by a hash. | GoBD 8, 9 |
| DOC-5 | Scanning paper receipts and destroying the originals (*ersetzendes Scannen*) is only allowed with a documented procedure. | GoBD 9.3 |

### Imports and upstream systems (IMP)

| ID | Requirement | Basis |
| --- | --- | --- |
| IMP-1 | When data is imported from an upstream system (e.g. a POS export), the **original import file MUST** be retained, so the import can be verified. | AO § 147 Abs. 2; GoBD 3.1, 9 |
| IMP-2 | Imported entries **MUST** keep their original transaction time and additionally store the import time as recording time (see REC-4). | GoBD 3.2.3 |
| IMP-3 | An import **MUST** be atomic: either all entries of a file are recorded or none. | GoBD 3.2.1 |
| IMP-4 | Importing the same data twice **SHOULD** be detected and rejected. | GoBD 3.2.2 |

### VAT (TAX)

| ID | Requirement | Basis |
| --- | --- | --- |
| TAX-1 | Amounts **MUST** be recorded separated by VAT rate, so that the tax base per rate can be determined. | UStG § 22; UStDV § 63 |
| TAX-2 | Totals per VAT rate **SHOULD** be available per day and per period. | UStG § 22 |
| TAX-3 | Monetary amounts **MUST** be stored and processed as exact decimals. Rounding **SHOULD** be documented. | GoBD 3.2.2 |

### Retention and deletion (RET)

| ID | Requirement | Basis |
| --- | --- | --- |
| RET-1 | Cashbook entries, master data, logs and the procedural documentation **MUST** be retained for **10 years**. | AO § 147 Abs. 1 Nr. 1, Abs. 3; HGB § 257 |
| RET-2 | Receipts **MUST** be retained for **8 years** (since 2025). | AO § 147 Abs. 1 Nr. 4, Abs. 3; HGB § 257 |
| RET-3 | The period starts at the end of the calendar year of the last entry. It does not end while the assessment period of a tax for which the records matter is still open. | AO § 147 Abs. 3, 4 |
| RET-4 | During the retention period, records **MUST** stay available, readable without delay and machine-evaluable, also across software updates, migrations and system changes. A printout or PDF does **not** replace the electronic records. | AO § 146 Abs. 5, § 147 Abs. 2; GoBD 9 |
| RET-5 | Deleting a cashbook or a user **MUST NOT** remove records that are still within their retention period. Such cashbooks **SHOULD** be archived (read-only) or transferred to another owner instead. | AO § 147; GDPR Art. 17 Abs. 3 lit. b |
| RET-6 | After the retention period has ended, personal data **SHOULD** be deleted. | GDPR Art. 5 Abs. 1 lit. e |
| RET-7 | Electronic records may be kept in another EU member state without approval; outside the EU only with approval of the tax office. This concerns the operator of the Nextcloud instance. | AO § 146 Abs. 2–2b |

### Access control and internal control (ACC)

| ID | Requirement | Basis |
| --- | --- | --- |
| ACC-1 | Access **MUST** be restricted to authorized users. Permissions **MUST** be checked on the server for every operation. | GoBD 6 |
| ACC-2 | There **SHOULD** be distinct roles, at least: owner, user who records entries, and read-only (tax advisor, auditor). | GoBD 6; AO § 147 Abs. 6 |
| ACC-3 | The system **SHOULD** run plausibility checks: no negative balance, consistent balance chain, no entries in a locked day, valid VAT rates. | GoBD 6 |

### Data security (SEC)

| ID | Requirement | Basis |
| --- | --- | --- |
| SEC-1 | Data **MUST** be protected against loss (backups), unauthorized access and manipulation. | GoBD 7 |
| SEC-2 | Backups are the responsibility of the Nextcloud operator. The documentation **SHOULD** state what has to be backed up (database tables and stored files of the app). | GoBD 7, 10.1 |

### Data access by the tax authority (AUD)

| ID | Requirement | Basis |
| --- | --- | --- |
| AUD-1 | **Z1, direct access**: it **MUST** be possible to give an auditor read-only access to all data in the app, including filtering and sorting. | AO § 147 Abs. 6; GoBD 11 |
| AUD-2 | **Z2, indirect access**: evaluations requested by the auditor **SHOULD** be possible with the app's own filters and reports. | AO § 147 Abs. 6; GoBD 11 |
| AUD-3 | **Z3, data carrier**: a complete export of all entries (including reversals), master data, logs and receipt references **MUST** be available in a machine-evaluable format. The de-facto standard is CSV with an `index.xml` according to the *Beschreibungsstandard für die Datenträgerüberlassung* (GDPdU), readable by the auditing software IDEA. | AO § 147 Abs. 6; GoBD 11 |
| AUD-4 | After a system change, direct and indirect access **MUST** remain possible for 5 years; afterwards providing the data carrier is sufficient. | AO § 147 Abs. 6 |
| AUD-5 | During an unannounced *Kassen-Nachschau* the records of the current and past days **MUST** be presentable immediately, on screen, printed and as export. | AO § 146b |

### Procedural documentation (DOCU)

| ID | Requirement | Basis |
| --- | --- | --- |
| DOCU-1 | The taxpayer **MUST** have a procedural documentation that lets an expert third party understand the process in reasonable time. It consists of a general description, user documentation, technical system documentation and operations documentation. | GoBD 10.1 |
| DOCU-2 | The app **SHOULD** provide the user and technical system documentation the taxpayer can refer to: data model, correction logic, permissions, locking, export format, backup notes. | GoBD 10.1 |
| DOCU-3 | Records and documentation may be in any living language, but the tax office can demand a translation at the taxpayer's expense. User-facing documentation and templates **SHOULD** therefore be available in German. | AO § 146 Abs. 3, § 87 |
| DOCU-4 | Changes of the software that affect the process **SHOULD** be documented with version and date (changelog), since the procedural documentation has to be retained in the version valid at the time. | GoBD 10.1; AO § 147 Abs. 1 Nr. 1 |

## Out of scope

- **Electronic cash registers** (AO § 146a, KassenSichV): the technical security device (TSE), the receipt obligation, the registration with the tax office and the DSFinV-K export only apply to systems with a cash register function, i.e. systems that record sales.
  A cashbook that only records cash movements is not such a system. The app **MUST NOT** add sales recording without implementing these requirements.
  Users of a POS system keep its DSFinV-K export in addition to the cashbook.
- **Certification**: there is no official certification for GoBD compliance (GoBD 12). A software audit report (e.g. IDW PS 880) is optional.
- **Double-entry bookkeeping**: the app records the cash account only. Posting to a general ledger happens in the accounting software, e.g. via an export.
