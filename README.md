# GSMaRT Construction System

Construction management, project costing and financial accounting in one web system, for general contractors that
run several projects at once. Every project is tracked from the lead or tender to close-out and warranty: contract,
estimate, budget, schedule, materials, workforce, equipment, daily site reports, milestones, change orders,
subcontractors, progress billing, collections, retention, revenue recognition, shared costs, quality and safety.

The accounting is the complete G-SMaRT Accounting engine (chart of accounts, general ledger, receivables, payables,
inventory, fixed assets, banking, BIR withholding and VAT reports, point of sale). The construction side posts into it
directly: a project **is** an accounting class, a work item **is** a cost code on the ledger lines, a client is a customer,
a supplier or subcontractor is a vendor, and materials are inventory items. Nothing is re-keyed and nothing is exported
between the two: a timesheet approved on site is labor cost in the general ledger the moment it is approved.

Plain HTML, CSS, JavaScript, PHP and MySQL. No framework, no build step, no internet connection needed (Bootstrap,
its icons and the fonts are inside the folder). Several companies run on one installation, each with its own database.

---

## 1. Requirements

| | |
|---|---|
| PHP | 8.1 or newer (8.2+ recommended) with `pdo_mysql` and `mbstring` (both on by default in XAMPP, WAMP and Laragon) |
| Database | MySQL 5.5.3 or newer (5.5, 5.6, 5.7, 8.x) or MariaDB 5.5 or newer |
| Web server | Apache (the `.htaccess` files are included) or nginx (section 10) |
| Browser | Any current browser on a computer, tablet or phone |

The database can be as old as MySQL 5.5: the tables use only what MySQL 5.5 supports (one `CURRENT_TIMESTAMP`
column per table, indexed text of at most 191 characters, no JSON columns, no window functions, no common table
expressions). PHP must still be 8.1 or newer; old XAMPP packages that bundle MySQL 5.5 also bundle PHP 5, which will not
run the system. Use a current PHP pointed at the MySQL 5.5 server (set the server in `config/config.php`).

## 2. Install with phpMyAdmin (easiest)

1. Unzip the folder into the web root, for example `C:\xampp\htdocs\gsmart-construction\` (so that
   `C:\xampp\htdocs\gsmart-construction\index.php` exists).
2. Start Apache and MySQL.
3. Open phpMyAdmin, click **Import** (no database needs to be selected first), choose **one** of these files and click **Go**:

   | File | What is in it |
   |---|---|
   | `database/gsmart_construction_clean.sql` | The whole system with **clean data only**: chart of accounts for a contractor, cost codes (WBS), trades and rates, checklists, approval routes, tax codes and the withholding tax table, units of measure, roles with their page access, and the user accounts below. No client, supplier, project or transaction. Start real work with this one. |
   | `database/gsmart_construction_sample.sql` | The same set-up **with all the sample data**: four projects at different stages and a full year of work on them in every module (section 5). Use it to learn and to demonstrate the system. |

   The sample file is about 15 MB. If phpMyAdmin says the file is too large, raise `upload_max_filesize` and
   `post_max_size` in `php.ini` to 64M (XAMPP: Apache > Config > PHP (php.ini)) and restart Apache, or import from the
   command line: `mysql -u root -p < database/gsmart_construction_sample.sql`.

   Each file creates two databases: `gsmart_construction` (the company: every record of the business) and
   `gsmart_construction_master` (the platform: the companies, their Company Codes and the Super Admin). Importing
   replaces every table in both.
4. If your MySQL user is not `root` with the password in `config/config.php`, edit `config/config.php` (`db` section).
5. Make the `storage` folder writable by the web server (on Windows it already is).
6. Open `http://localhost/gsmart-construction/` and log in (section 4).

The file `storage/platform/multi_company.json` tells the program that the platform database is set up. It is
already in the folder. If you rename the company database, change `primary_db` in that file (or delete the file and
run the Multi-Company Setup again from System Administration).

## 3. Install from the command line (alternative)

```bash
php tools/install.php --drop                          # clean set-up (DELETES both databases first)
php tools/install.php --drop --demo                   # set-up plus the sample year (about a minute)
php tools/install.php --drop --company="ABC Builders" # clean set-up under your company's name
php tools/export_sql.php --out=database/backup.sql    # one importable file of both databases
```

Database settings come from `config/config.php`, or the environment variables `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER` and `DB_PASS`. The sample dates count back from the day the installer runs, so aging, schedules and dashboards
always look current.

## 4. User accounts, from the lowest level to the Super Admin

Every company user has one of 25 standard roles (they can be changed, copied or added under System Administration >
User Role Configuration and Page Access Configuration). Approvals follow the routes in Approvals > Approval Routes, and nobody approves a document
they submitted (maker-checker).

| # | Username | Password | Name | Role | What the role does |
|---|---|---|---|---|---|
| 1 | `subcon` | `Demo@12345` | Allan Torres | Subcontractor Portal User | Portal only: the subcontractor's own agreements, progress claims (submitted with their invoice), retention and payments. Linked to Allied Electrical Contractors. |
| 2 | `client` | `Demo@12345` | Atty. Ramon Velasco | External Client / Client Representative | Portal only: progress, milestones, billings to certify, change orders to approve, statements, documents and photos of the client's own projects. Linked to Northgate Land Development Corp. |
| 3 | `timekeeper` | `Demo@12345` | Pedro Castillo | Timekeeper | Daily time in and time out of the crews. |
| 4 | `foreman` | `Demo@12345` | Nestor Ramos | Site Supervisor / Foreman | Crew attendance, daily site reports, material requests and equipment logs on site. |
| 5 | `warehouse` | `Demo@12345` | Dan Villanueva | Warehouse Officer | Receiving and inspection, stock, issues, returns and transfers of materials. |
| 6 | `cashier` | `Demo@12345` | Ella Ramos | Collection Officer / Cashier | Client payments and official receipts, deposits, the yard sales counter. |
| 7 | `safety` | `Demo@12345` | Jose Mercado | Safety Officer | Incidents, near misses, toolbox meetings, training, PPE, permits to work. |
| 8 | `qa` | `Demo@12345` | Engr. Paolo Garcia | Quality Inspector | Inspection and test plans, checklists, material tests, nonconformance reports. |
| 9 | `billing` | `Demo@12345` | Maria Santos | Billing Officer | Progress billing applications, invoices, statements of account, retention. |
| 10 | `acctstaff` | `Demo@12345` | Ben Garcia | Accounting Staff | Bills, checks, receipts, deposits, journal entries, cost code assignment. |
| 11 | `procurement` | `Demo@12345` | Carla Lim | Procurement Officer | Canvass and quotations, purchase orders, supplier records. |
| 12 | `hr` | `Demo@12345` | Angela Torres | Human Resources Officer | Workers, trades and rates, cash advances, payroll runs. |
| 13 | `equipmgr` | `Demo@12345` | Rogelio Navarro | Equipment Manager | Equipment register, dispatch and booking, usage logs, maintenance. |
| 14 | `siteeng` | `Demo@12345` | Engr. Mark Dizon | Site Engineer | Site reports and accomplishments, inspections, RFIs, material requests; prepares billings. |
| 15 | `qs` | `Demo@12345` | Joy Fernandez | Quantity Surveyor | Estimates, BOQ and schedule of values, budgets, change orders, measurement of progress and subcontractor billings. |
| 16 | `auditor` | `Demo@12345` | Grace Uy | Internal Auditor | View-only access to every record, report and the audit trail. |
| 17 | `purchasing` | `Demo@12345` | Victor Chua | Purchasing Manager | Approves material requests and purchases, awards canvasses. |
| 18 | `controller` | `Demo@12345` | Ana Cruz, CPA | Chief Accountant / Controller | The books: period closing, revenue recognition, cost allocation, payroll posting, BIR reports. |
| 19 | `pm` | `Demo@12345` | Engr. Carlo Reyes | Project Manager | Runs Tower 2 and the C-5 road: approves their timesheets, site reports, equipment logs and requests. |
| 20 | `pm2` | `Demo@12345` | Engr. Liza Bautista | Project Manager | Runs the gymnasium and the warehouse project. |
| 21 | `finmgr` | `Demo@12345` | Michelle Tan | Finance Manager | Cash, collections and payments; approves billings, payroll, budgets and allocations. |
| 22 | `projdir` | `Demo@12345` | Engr. Ramon Aquino | Project Director | Contracts, budgets, schedules, change orders and the director-level approvals. |
| 23 | `opsmgr` | `Demo@12345` | Teresa Mendoza | Operations Manager | All field operations across the projects, with approval rights on them. |
| 24 | `gm` | `Demo@12345` | Ricardo Villanueva | General Manager | Every project and report; the authorized-approver step of contracts, billings and large purchases. |
| 25 | `owner` | `Demo@12345` | Eduardo Santos | Business Owner / President (Company Owner) | Every project, report and transaction, and the final executive approvals. |
| 26 | `admin` | `Admin@12345` | System Administrator | Company Administrator | Everything in the company, including users, roles, page access, settings and the closing date. |
| 27 | `superadmin` | `Super@12345` | Platform Super Administrator | Super Admin (platform) | Adds and manages companies (each with its own database and Company Code), their owners and the platform settings. Belongs to no company. |

Every user logs in on the same page; the system finds their company from the username. The Super Admin lands on the
platform pages; from there **Companies > New company** creates a new company with an empty database copied from the
set-up of Company 1 (chart of accounts, cost codes, roles, approval routes, settings), and its Company Owner account.
New employees can join a company with its Company Code from the **Join your company** link under the log-in form.

**Change every password above (or deactivate the accounts you do not need) before real use.**

## 5. The sample data

`gsmart_construction_sample.sql` holds one year of a contractor, Sample Builders & Development Corp., up to 23 October 2026, recorded through
the same screens and rules a user goes through (every approval step was taken by the right person, every amount posted
to the ledger), so every report agrees with the books.

| Project | Client | Contract | Where it stands |
|---|---|---|---|
| PRJ-0001 St. Benedict School Gymnasium | St. Benedict School Foundation | Lump sum, 44.2M | Finished six weeks ago, under warranty: punch list, close-out checklist, final acceptance in progress, retention release waiting for approval. Its costs were typed in accounting (bills and checks) straight to the project and its cost codes. |
| PRJ-0002 Northgate Residences Tower 2 | Northgate Land Development Corp. | Lump sum, 128.5M + change order | About 37% done. Daily timesheets, site reports and equipment logs since it started; monthly material requests, canvasses and purchase orders; two subcontractors with an advance, retention and monthly billings; an advance billing and five progress billings (one overdue and disputed); a client-approved change order with its budget revision and another one waiting for approval; milestones, inspections, an open NCR, safety records, RFIs and drawings. |
| PRJ-0003 C-5 Service Road Widening | DPWH Metro Manila 1st DEO | Unit price, 44.6M + change order | About 75% done. Quantity-based billings with 2% income tax and 5% VAT withheld by the government, an extension of time for rain days, a rented roller and the company's own fleet. |
| PRJ-0004 Pacific Agri-Foods Warehouse | Pacific Agri-Foods Corp. | Lump sum, 31.8M | Just won from a tender: estimate approved, budget being prepared, contract waiting for the General Manager's approval. |

Also in it: the leads and tenders list, 18 workers in two crews (two of them from a labor-only cooperative), ten
units of equipment (owned with their fixed asset records, leased and rented), semi-monthly payroll with cash advances
deducted, monthly revenue recognition by percentage of completion, the yard and warehouse cost allocated to the projects
every month, depreciation, supplier payments with withholding tax, and documents waiting in every approval inbox.

## 6. What is inside

**Construction (the left menu: Projects, Field, Supply, Billing, Approvals, Portal)**

- **Projects:** project center and the 360-degree project page (overview, budget and cost, schedule, billing, materials,
  labor and equipment, quality and safety, documents), clients, contracts with bonds and insurance, change orders and
  claims, documents and drawings with revisions, RFIs and submittals, risks, issues, decisions and meeting minutes,
  close-out with punch list and warranty.
- **Planning & Estimating:** leads and tenders, estimates (with markup and contingency, versions), BOQ and schedule of
  values, budgets with revisions (the original baseline is never changed), schedule and Gantt chart with
  predecessors, critical path, baseline, look-ahead and earned value (CPI, SPI, EAC), milestones with their
  deliverables and billing eligibility, extensions of time, cost codes.
- **Site Operations:** daily site reports (weather, manpower, equipment, accomplishments that update the schedule,
  delays, photos; they can be written offline on a phone and sent later), inspections, nonconformance reports, safety.
- **Workforce:** workers and trades, timesheets (time in and out, overtime, late and undertime, no worker on two sites at
  the same time), payroll runs, cash advances.
- **Equipment:** register, dispatch and booking (one unit is never on two projects at once), usage logs and charges.
- **Procurement & Materials:** material requests checked against the budget, canvass and comparison of quotations,
  award and purchase order, receiving and inspection (rejected quantities go back on a debit memo), issues, returns and
  transfers between projects, project stockyards.
- **Subcontracts:** subcontractor register with accreditation, agreements, progress billings checked against the
  accomplishment verified on site, retention, advances and their recoupment, back charges.
- **Billing & Revenue:** progress billing on the schedule of values, by quantity, by milestone, time and materials,
  advance and retention release; client certification and invoice; revenue recognition (percentage of completion by cost
  or by physical progress, completed contract, or billings) with the provision for losses; collections and retention.
- **Cost Control:** shared cost allocation (equal, man-hours, equipment hours, direct cost, contract value, revenue,
  floor area, fixed shares) with reversal and reallocation, allocation rules, cost code assignment of costs typed in
  accounting.
- **Approvals:** inbox, history, and the approval routes (by document, amount, project and risk; steps by role, by user
  or by the project's own manager, engineer or quantity surveyor; delegation and escalation).
- **Portals:** the client portal and the subcontractor portal.
- **Project Reports (29):** portfolio summary, project income statement, cost breakdown, budget versus actual, committed
  costs, estimate at completion, estimate to complete, project cash flow, profitability forecast, work in progress
  (over- and under-billings), earned revenue versus billing, client profitability, billing statement, collections, AR
  aging by project, retention, project payables, material consumption and wastage, man-hours and labor cost, labor
  productivity, equipment utilization, subcontractor statement, cost allocations, supplier spend, change orders,
  contract summary, earned value, milestones and deliverables, quality and safety.
- **Dashboards:** executive, project manager and finance, each limited to the projects the user may see.

**Accounting (the Accounting menu)** - Financial Set-up & Reports, Fixed Assets, Customers, Vendors, Inventory, Point of
Sale, Banking and BIR Reports, exactly as in G-SMaRT Accounting, with every report filterable by class (project).

**How the two sides post** (the accounts are set in Financial Set-up > Module Default Accounts):

| Construction document | Entry |
|---|---|
| Timesheet approved | Dr Direct Labor / Site Supervision and Labor Burden, Cr Accrued Payroll and SSS-PhilHealth-Pag-IBIG, by project and cost code |
| Payroll run approved | Dr Accrued Payroll, Cr Salaries Payable, Employee Advances and the employees' contributions |
| Equipment log approved | Dr Equipment Costs Charged (project, cost code), Cr Equipment Cost Recovery (company) |
| Material issue | Inventory adjustment: Dr Direct Materials (project, cost code), Cr Inventory |
| Transfer between projects | Dr materials cost of the receiving project, Cr the giving project |
| Subcontractor billing approved | Bill: Dr Subcontract Costs + Input VAT, Cr Retention Payable and Accounts Payable; the advance applied |
| Advance billing certified | Invoice: Dr Accounts Receivable, Cr Advances from Clients + Output VAT |
| Progress billing certified | Invoice: Dr Accounts Receivable, Retention Receivable and Advances from Clients (recoupment), Cr Progress Billings + Output VAT |
| Revenue recognition approved | Dr Contract Assets, Cr Contract Revenue (and the loss provision when a loss is expected) |
| Allocation approved | The shared expense moved from the company to each project's class |

## 7. Rules the system keeps

Maker-checker on every approval; amount limits by role; no billing past 100% of a line or of the revised contract
without an authorized change order; materials, subcontracts and purchases checked against the remaining budget (warn or
block, a setting); an approved contract or budget is never edited (change orders and revisions instead); a timesheet
cannot put a worker on two projects at the same time or over the daily maximum; one equipment unit per project per
day; subcontractor billings limited to the verified accomplishment; the same supplier invoice is never recorded twice;
a project cannot be closed with open purchase orders, unpaid bills, retention, unrecognized revenue, pending approvals,
open punch items or NCRs unless the exception is approved; posted documents are reversed, never deleted; every change
is kept in the audit trail, which the database itself protects from edits.

## 8. Settings worth checking first

- System Administration > **Construction Settings**: revenue recognition method, when materials become project cost
  (when issued or when used), billing past the schedule of values, budget control, retention, regular and maximum hours,
  who sees which project, maker-checker.
- System Administration > **Company, Security & Closing Date**: company details, logo, password rules, closing date.
- Financial Set-up > **Module Default Accounts**: the construction accounts listed in section 6.
- Approvals > **Approval Routes**, Planning > **Cost Codes**, Workforce > **Trades & Rates**.

## 9. Folders

| Folder | What is in it |
|---|---|
| `app/` | The classes: accounting engine (`Txn`, `Posting`, `Balances`, ...) and the construction classes (`Cs*.php`); `app/reports/` holds the report definitions |
| `modules/` | One folder per menu; each page is one PHP file |
| `views/` | Layout, log-in and the User's Manual chapters |
| `assets/` | CSS, JavaScript, Bootstrap, icons and fonts (no internet needed) |
| `database/` | `schema.sql`, `triggers.sql`, `master_schema.sql`, the two importable files and `migrations/` for later versions |
| `storage/` | Uploaded files, photos, documents and backups (must be writable; never served directly) |
| `tools/` | Installer, sample data generator, SQL export |
| `config/config.php` | Database connection and options |

## 10. nginx

Point the root at the folder, send `.php` to PHP-FPM, and deny the private folders:

```nginx
location ~ ^/(app|config|database|storage|tools|views)/ { deny all; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass 127.0.0.1:9000; }
```

## 11. Backups

System Administration > **Backup & Restore** downloads a copy of the company database, or run
`php tools/export_sql.php`. Keep the `storage` folder with it: the documents, photos and attachments are there.
#   c o n s t r u c t i o n  
 