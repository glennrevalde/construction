<?php
declare(strict_types=1);

/**
 * Setting companies up.
 *
 *  - setupPrimary(): the current database becomes Company 1. Nothing in it is moved, reset or deleted;
 *    it is registered in the master database with a Company Code and a Company Owner chosen from its own
 *    users, and every existing user is listed in the master database so the log-in page can find them.
 *
 *  - create(): a new company with a database of its own, made from a template company (Company 1 unless
 *    another is chosen). The steps are logged one by one in company_provisioning_log; the company is
 *    'provisioning' while they run and only becomes 'active' when all of them succeeded. If one fails,
 *    the database this attempt created is dropped again, the company is marked 'failed' with the reason,
 *    and the Super Admin can try again (retry) or delete the failed record.
 *
 * What a new company database receives from the template:
 *   - the complete structure: every table with its columns, keys, indexes and foreign keys, exactly as the
 *     template has them today (SHOW CREATE TABLE), with the counters starting from 1;
 *   - the audit trail protection triggers (database/triggers.sql);
 *   - the set-up lists in COPY (chart of accounts, module default accounts, tax codes, the withholding tax table,
 *     terms, payment methods, price levels, units of measure, item and customer types, fixed asset categories, the roles with their
 *     page access and special permissions, the pages and modules, the numbering, and the settings);
 *   - one inventory site, "Main".
 * Nothing else: no customers, suppliers, items, employees or users, no transactions, balances, journals,
 * payments, budgets, audit trail or history. Its only user is the Company Owner created with it.
 */
final class Provision
{
    /** set-up tables copied from the template, with what is changed on the copy */
    public const COPY = [
        'settings'                 => 'Company, security, POS receipt and item search settings (company details replaced)',
        'modules'                  => 'The modules of the menu',
        'pages'                    => 'Every page, for page access',
        'roles'                    => 'User roles (all sites allowed, since the template\'s sites are not copied)',
        'role_page_access'         => 'Which pages each role opens, and what it may do there',
        'role_special_permissions' => 'Special permissions of each role (costs, limits, report design, ...)',
        'sequences'                => 'Document numbering (every counter starts again from 1)',
        'accounts'                 => 'Chart of accounts (bank account numbers left blank; balances are never copied)',
        'default_accounts'         => 'Module default accounts',
        'terms'                    => 'Terms',
        'tax_codes'                => 'Tax codes',
        'payment_methods'          => 'Payment methods',
        'price_levels'             => 'Price level names',
        'customer_types'           => 'Customer types',
        'billing_groups'           => 'Billing groups',
        'item_categories'          => 'Item categories',
        'uom_sets'                 => 'Units of measure',
        'uom_units'                => 'Units of measure (units)',
        'asset_categories'         => 'Fixed asset categories and their accounts',
        'withholding_taxes'        => 'Withholding Tax Table (BIR Reports)',
        'cs_cost_codes'            => 'Construction cost codes (work breakdown) with their cost accounts',
        'cs_trades'                => 'Trades and their daily rates',
        'cs_checklist_templates'   => 'Checklists for inspections, milestones, tenders and closeout',
        'cs_approval_routes'       => 'Approval routes (the company-wide ones; routes of single projects are left out)',
        'cs_approval_steps'        => 'Approval route steps (by role or project role; steps naming one template user go to the Administrator role)',
        'cs_alloc_rules'           => 'Shared-cost allocation rules (without project shares, since projects are not copied)',
    ];

    /** settings that describe the template company itself, replaced or cleared on the copy */
    private const COMPANY_SETTINGS = ['company_name', 'company_address', 'company_tin', 'company_phone', 'company_email', 'company_logo', 'closing_date',
        'bir_zip_code', 'bir_signatory_name', 'bir_signatory_title', 'bir_signatory_tin'];

    // ================================================================== Company 1

    /**
     * Registers the database of config.php as Company 1 and switches multi-company on.
     * $sa = [username, full_name, email, password]; $company = [name, address, contact_person, phone, email, tin];
     * $ownerId = one of this database's users. Runs in the first company's own session (single mode).
     */
    public static function setupPrimary(array $sa, array $company, int $ownerId, bool $requireApproval): array
    {
        if (Master::enabled()) {
            fail('Multi-company is already set up.');
        }
        $owner = DB::row("SELECT * FROM users WHERE id = :id AND status = 'active' AND is_platform = 0", ['id' => $ownerId]);
        if (!$owner) {
            fail('Choose an active user of this company as its Company Owner.');
        }
        if (DB::value('SELECT id FROM users WHERE username = :u', ['u' => $sa['username']])) {
            fail('The Super Admin needs a username of its own: ' . $sa['username'] . ' is already a user of this company.');
        }

        $masterDb = Master::install();
        $m = Master::sql();
        $primaryDb = Master::primaryDb();

        // a master database that already knows this company (switch file removed or storage copied): reconnect
        $known = $m->row('SELECT company_id FROM company_databases WHERE db_name = :d', ['d' => $primaryDb]);
        if ($known) {
            Master::writeFlag($masterDb);
            $_SESSION['tenant_id'] = (int)$known['company_id'];
            Master::log('platform_reconnected', (int)$known['company_id'], 'Multi-company switched on again for the existing master database');
            return ['company_id' => (int)$known['company_id'], 'code' => (string)(Master::company((int)$known['company_id'], true)['code'] ?? ''), 'reconnected' => true];
        }
        if ((int)$m->value('SELECT COUNT(*) FROM companies') > 0) {
            fail("The master database $masterDb already belongs to other companies. Name another one in config/config.php (\$CONFIG['master']['name']).");
        }
        if ($m->value('SELECT id FROM super_admins WHERE username = :u', ['u' => $sa['username']])) {
            fail('That Super Admin username is already taken.');
        }

        // the audit trail protection may have been lost by an earlier restore: put it back while we are here
        $triggerProblem = Audit::ensureTriggers();

        $companyId = 0;
        $code = '';
        $m->tx(function (Sql $m) use ($sa, $company, $owner, $requireApproval, $primaryDb, $triggerProblem, &$companyId, &$code) {
            $companyId = $m->insert('companies', [
                'name' => $company['name'], 'address' => $company['address'] ?: null, 'contact_person' => $company['contact_person'] ?: null,
                'phone' => $company['phone'] ?: null, 'email' => $company['email'] ?: null, 'tin' => $company['tin'] ?: null,
                'status' => 'active', 'owner_user_id' => (int)$owner['id'], 'require_approval' => $requireApproval ? 1 : 0,
                'activated_at' => date('Y-m-d H:i:s'), 'notes' => 'The original database, registered as Company 1',
            ]);
            $m->insert('company_databases', [
                'company_id' => $companyId, 'db_name' => $primaryDb, 'is_primary' => 1, 'created_by_platform' => 0,
                'table_count' => (int)DB::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"),
                'triggers_ok' => $triggerProblem === '' ? 1 : 0, 'verified_at' => date('Y-m-d H:i:s'),
            ]);
            $m->insert('super_admins', [
                'username' => $sa['username'], 'password_hash' => Auth::hash($sa['password']), 'full_name' => $sa['full_name'],
                'email' => $sa['email'] ?: null, 'status' => 'active', 'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s'),
            ]);
            $m->run("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('default_require_approval', :v)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", ['v' => $requireApproval ? '1' : '0']);
        });
        $code = CompanyCode::generate($company['name']);
        $m->insert('company_codes', ['company_id' => $companyId, 'code' => $code, 'status' => 'active', 'created_by' => 'setup:' . (Auth::user()['username'] ?? '')]);

        // every existing user, so the log-in page knows they belong to Company 1 (the owner too)
        $sync = Membership::sync($companyId, new Sql(DB::pdo()));

        Master::writeFlag($masterDb);
        $_SESSION['tenant_id'] = $companyId;
        Master::log('platform_setup', $companyId, 'The existing database was registered as Company 1 (' . $company['name'] . '); owner '
            . $owner['username'] . '; Super Admin ' . $sa['username'] . ' created', ['user', (int)Auth::id(), Auth::user()['username'] ?? null]);
        Audit::log('multi_company_setup', 'admin', 'company', $companyId, $company['name'], [
            'reason' => 'Registered as Company 1 of the platform. Company Owner: ' . $owner['username'] . '. Super Admin: ' . $sa['username'] . '.',
            'page_key' => 'admin.multi_company',
        ]);
        return ['company_id' => $companyId, 'code' => $code, 'users' => $sync['users'], 'conflicts' => $sync['conflicts'],
            'master_db' => $masterDb, 'trigger_problem' => $triggerProblem, 'reconnected' => false];
    }

    // ================================================================== new companies

    /**
     * Creates a company and sets up its database. Returns the company id; its status says whether it worked.
     * $data = company fields; $owner = [username, full_name, email, password] or null (assign later).
     */
    public static function create(array $data, ?array $owner, int $templateId, int $superAdminId): int
    {
        $m = Master::sql();
        $template = Master::company($templateId, true);
        if (!$template || $template['status'] !== 'active' || (string)$template['db_name'] === '') {
            fail('Choose an active company to copy the set-up from.');
        }
        if ($m->value('SELECT id FROM companies WHERE name = :n', ['n' => $data['name']])) {
            fail('There is already a company named ' . $data['name'] . '.');
        }
        if ($owner) {
            self::checkOwner($owner, Sql::to((string)$template['db_name']));
        }
        $companyId = $m->insert('companies', [
            'name' => $data['name'], 'address' => $data['address'] ?: null, 'contact_person' => $data['contact_person'] ?: null,
            'phone' => $data['phone'] ?: null, 'email' => $data['email'] ?: null, 'tin' => $data['tin'] ?: null,
            'notes' => $data['notes'] ?: null, 'status' => 'provisioning', 'require_approval' => !empty($data['require_approval']) ? 1 : 0,
            'template_company_id' => $templateId, 'created_by' => $superAdminId,
        ]);
        $code = CompanyCode::generate($data['name']);
        $m->insert('company_codes', ['company_id' => $companyId, 'code' => $code, 'status' => 'active', 'created_by' => 'super:' . $superAdminId]);
        Master::log('company_created', $companyId, 'Company record created: ' . $data['name'] . '; set-up copied from ' . $template['name']);
        self::build($companyId, $template, $owner, 1);
        return $companyId;
    }

    /** Runs the set-up of a failed (or stuck) company again */
    public static function retry(int $companyId, ?array $owner): bool
    {
        $c = Master::company($companyId, true);
        if (!$c || !self::canRetry($c)) {
            fail('Only a company whose set-up failed can be set up again.');
        }
        $template = Master::company((int)($c['template_company_id'] ?: Master::primaryCompanyId()), true);
        if (!$template || $template['status'] !== 'active') {
            fail('The company its set-up was copied from is not active any more.');
        }
        if ($owner) {
            self::checkOwner($owner, Sql::to((string)$template['db_name']));
        }
        $attempt = 1 + (int)Master::sql()->value('SELECT COALESCE(MAX(attempt), 0) FROM company_provisioning_log WHERE company_id = :c', ['c' => $companyId]);
        Master::sql()->update('companies', ['status' => 'provisioning', 'last_error' => null], 'id = :id', ['id' => $companyId]);
        Master::log('company_setup_retried', $companyId, 'Set-up started again (attempt ' . $attempt . ')');
        return self::build($companyId, $template, $owner, $attempt);
    }

    public static function canRetry(array $c): bool
    {
        return $c['status'] === 'failed'
            || ($c['status'] === 'provisioning' && strtotime((string)$c['created_at']) < time() - 900 && !(int)($c['is_primary'] ?? 0));
    }

    /** Removes the record of a company whose set-up failed (its database is already gone) */
    public static function deleteFailed(int $companyId): void
    {
        $c = Master::company($companyId, true);
        if (!$c || $c['status'] !== 'failed') {
            fail('Only a company whose set-up failed can be deleted. Deactivate an active company instead.');
        }
        self::dropCreatedDatabase($companyId);
        Master::sql()->tx(function (Sql $m) use ($companyId) {
            $m->run('DELETE FROM user_company_memberships WHERE company_id = :c', ['c' => $companyId]);
            $m->run("UPDATE company_codes SET status = 'retired', retired_at = NOW() WHERE company_id = :c AND status <> 'retired'", ['c' => $companyId]);
            $m->run('DELETE FROM company_databases WHERE company_id = :c', ['c' => $companyId]);
            $m->run('DELETE FROM companies WHERE id = :c', ['c' => $companyId]);
        });
        Master::forget($companyId);
        Master::log('company_deleted', $companyId, 'Failed company record deleted: ' . $c['name']);
    }

    /** Checks an owner account before anything is created */
    private static function checkOwner(array $owner, Sql $template): void
    {
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $owner['username'])) {
            fail('The owner\'s username: 3 to 50 characters, letters, numbers, dot, dash or underscore.');
        }
        if (!Master::usernameFree($owner['username'])) {
            fail('The username ' . $owner['username'] . ' is already taken on this platform.');
        }
        if (trim((string)$owner['full_name']) === '') {
            fail('Enter the owner\'s full name.');
        }
        if (($owner['email'] ?? '') !== '' && !filter_var($owner['email'], FILTER_VALIDATE_EMAIL)) {
            fail('The owner\'s e-mail address does not look right.');
        }
        $settings = $template->pairs('SELECT setting_key, setting_value FROM settings');
        if ($problems = Auth::passwordProblems((string)$owner['password'], $owner['username'], $settings)) {
            fail('The owner\'s temporary password must have ' . implode(', ', $problems) . '.');
        }
    }

    // ------------------------------------------------------------------ the steps

    private static function build(int $companyId, array $template, ?array $owner, int $attempt): bool
    {
        $m = Master::sql();
        $c = Master::company($companyId, true);
        $step = 'start';
        $log = function (string $name, bool $ok, string $msg = '') use ($m, $companyId, $attempt) {
            $m->insert('company_provisioning_log', ['company_id' => $companyId, 'attempt' => $attempt, 'step' => $name, 'ok' => $ok ? 1 : 0, 'message' => $msg ?: null]);
        };
        @set_time_limit(0);
        try {
            $tplDb = (string)$template['db_name'];
            $tpl = Sql::to($tplDb);
            $server = Sql::to(null);

            // 1. a database name nobody uses yet: <prefix>NNN
            $step = 'database_name';
            $dbName = self::freeDatabaseName($server, $companyId);
            $log($step, true, $dbName);

            // 2. the database, with the template's character set
            $step = 'create_database';
            $cs = $server->row('SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS co FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :n', ['n' => $tplDb]);
            $charset = preg_replace('/[^a-z0-9_]/i', '', (string)($cs['cs'] ?? 'utf8mb4')) ?: 'utf8mb4';
            $collate = preg_replace('/[^a-z0-9_]/i', '', (string)($cs['co'] ?? 'utf8mb4_unicode_ci')) ?: 'utf8mb4_unicode_ci';
            $server->exec("CREATE DATABASE `$dbName` CHARACTER SET $charset COLLATE $collate");
            $m->run('DELETE FROM company_databases WHERE company_id = :c', ['c' => $companyId]);
            $m->insert('company_databases', ['company_id' => $companyId, 'db_name' => $dbName, 'is_primary' => 0, 'created_by_platform' => 1]);
            $log($step, true, "Created with $charset / $collate");
            $new = Sql::to($dbName);
            $new->exec('SET FOREIGN_KEY_CHECKS = 0');

            // 3. every table, exactly as the template has it (columns, keys, indexes, foreign keys)
            $step = 'copy_structure';
            $tables = $tpl->column("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name");
            foreach ($tables as $t) {
                $ddl = (string)($tpl->row('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')['Create Table'] ?? '');
                if ($ddl === '') {
                    throw new RuntimeException("The structure of $t could not be read.");
                }
                $new->exec(preg_replace('/\sAUTO_INCREMENT=\d+/i', '', $ddl));
            }
            $log($step, true, count($tables) . ' tables');

            // 4. the set-up lists, and nothing else
            $step = 'copy_setup';
            $copied = [];
            foreach (array_keys(self::COPY) as $t) {
                if (!in_array($t, $tables, true)) {
                    continue;
                }
                $new->exec("INSERT INTO `$t` SELECT * FROM `$tplDb`.`$t`");
                $copied[] = $t . ' (' . (int)$new->value("SELECT COUNT(*) FROM `$t`") . ')';
            }
            // the copy describes the new company, not the template
            $values = ['company_name' => $c['name'], 'company_address' => (string)$c['address'], 'company_tin' => (string)$c['tin'],
                'company_phone' => (string)$c['phone'], 'company_email' => (string)$c['email'], 'company_logo' => '', 'closing_date' => ''];
            foreach (self::COMPANY_SETTINGS as $k) {
                $new->run('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    ['k' => $k, 'v' => $values[$k] ?? '']);
            }
            $new->exec('UPDATE sequences SET next_no = 1');
            $new->exec('UPDATE accounts SET bank_account_no = NULL');
            $new->exec('UPDATE roles SET all_sites = 1');
            // construction set-up: nothing may point at the template's projects or users
            $new->exec('DELETE FROM cs_approval_steps WHERE route_id IN (SELECT id FROM cs_approval_routes WHERE project_id IS NOT NULL)');
            $new->exec('DELETE FROM cs_approval_routes WHERE project_id IS NOT NULL');
            $new->exec('UPDATE cs_approval_steps SET role_id = (SELECT id FROM roles WHERE is_admin = 1 ORDER BY id LIMIT 1) WHERE user_id IS NOT NULL AND role_id IS NULL AND project_role IS NULL');
            $new->exec('UPDATE cs_approval_steps SET user_id = NULL');
            if (!(int)$new->value('SELECT COUNT(*) FROM sites')) {
                $new->insert('sites', ['code' => 'MAIN', 'name' => 'Main', 'site_type' => 'warehouse', 'is_active' => 1]);
            }
            $log($step, true, implode(', ', $copied) . '; site MAIN added');

            // 5. the audit trail protection triggers
            $step = 'triggers';
            $triggerProblem = Audit::ensureTriggers($new);
            $log($step, $triggerProblem === '', $triggerProblem === '' ? 'Audit trail protection in place'
                : 'Not in place (the company works; the audit trail can then be changed directly in the database): ' . $triggerProblem);

            // 6. the Company Owner
            $step = 'owner_account';
            $ownerId = null;
            if ($owner) {
                $ownerId = self::createUser($new, $owner, 'provisioning', true);
                $log($step, true, 'Owner ' . $owner['username'] . ' created with the Administrator role');
            } else {
                $log($step, true, 'No owner yet: the Super Admin assigns one later');
            }

            // 7. check the result before anyone can use it
            $step = 'verify';
            $newTables = (int)$new->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
            if ($newTables !== count($tables)) {
                throw new RuntimeException("The new database has $newTables tables instead of " . count($tables) . '.');
            }
            if ($pending = Schema::pendingOn($new)) {
                throw new RuntimeException('The new database does not match the program: ' . implode(', ', array_map('basename', $pending))
                    . ' (bring the template company up to date first).');
            }
            foreach (['transactions', 'journal_lines', 'customers', 'vendors', 'items', 'audit_trail'] as $t) {
                if (in_array($t, $tables, true) && (int)$new->value("SELECT COUNT(*) FROM `$t`") > 0) {
                    throw new RuntimeException("$t is not empty.");
                }
            }
            $new->exec('SET FOREIGN_KEY_CHECKS = 1');
            $m->update('company_databases', ['table_count' => $newTables, 'triggers_ok' => $triggerProblem === '' ? 1 : 0,
                'verified_at' => date('Y-m-d H:i:s')], 'company_id = :c', ['c' => $companyId]);
            $log($step, true, "$newTables tables, structure matches the program, no records copied beyond the set-up lists");

            // 8. who belongs to it, its files, and the first line of its audit trail
            $step = 'register';
            $m->update('companies', ['owner_user_id' => $ownerId], 'id = :id', ['id' => $companyId]);
            Membership::sync($companyId, $new);
            self::storage($companyId);
            $new->insert('audit_trail', [
                'user_id' => null, 'username' => 'platform', 'user_roles' => 'Super Admin', 'ip_address' => client_ip(),
                'workstation' => 'Company set-up', 'module' => 'admin', 'page_key' => 'company.profile', 'action' => 'company_created',
                'record_type' => 'company', 'record_id' => $companyId, 'record_ref' => mb_substr((string)$c['name'], 0, 160),
                'reason' => 'Company database set up by the platform from the set-up of ' . $template['name'] . ($owner ? '; Company Owner ' . $owner['username'] : ''),
            ]);
            $log($step, true, 'Company registered with its database, code and owner');

            // 9. ready
            $step = 'activate';
            $m->update('companies', ['status' => 'active', 'activated_at' => date('Y-m-d H:i:s'), 'last_error' => null, 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id', ['id' => $companyId]);
            $log($step, true, 'Active');
            Master::forget($companyId);
            Master::log('company_activated', $companyId, 'Set-up finished: database ' . $dbName . ', ' . $newTables . ' tables');
            return true;
        } catch (Throwable $e) {
            $msg = $e instanceof UserError ? $e->getMessage() : $e->getMessage();
            $log($step, false, $msg);
            $cleanup = self::dropCreatedDatabase($companyId);
            $m->run('DELETE FROM user_company_memberships WHERE company_id = :c', ['c' => $companyId]);
            $m->update('companies', ['status' => 'failed', 'last_error' => "Step $step: $msg" . ($cleanup ? " ($cleanup)" : ''),
                'owner_user_id' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $companyId]);
            $log('cleanup', true, $cleanup ?: 'Nothing to remove');
            Master::forget($companyId);
            Master::log('company_setup_failed', $companyId, "Step $step: $msg");
            return false;
        }
    }

    /** <prefix>NNN, or <prefix>NNN_b, _c ... when a database of that name already exists (it is never touched) */
    private static function freeDatabaseName(Sql $server, int $companyId): string
    {
        $base = Master::dbPrefix() . str_pad((string)$companyId, 3, '0', STR_PAD_LEFT);
        foreach (array_merge([''], array_map(fn($l) => '_' . $l, range('b', 'z'))) as $suffix) {
            $name = substr($base, 0, 60) . $suffix;
            if (!DB::validName($name)) {
                throw new RuntimeException('The database name prefix gives an invalid name.');
            }
            $taken = (int)$server->value('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :n', ['n' => $name])
                + (int)Master::sql()->value('SELECT COUNT(*) FROM company_databases WHERE db_name = :n AND company_id <> :c', ['n' => $name, 'c' => $companyId]);
            if (!$taken) {
                return $name;
            }
        }
        throw new RuntimeException('No free database name was found.');
    }

    /** Drops the database the platform created for a company (never the first company's, never one it did not create) */
    private static function dropCreatedDatabase(int $companyId): string
    {
        $d = Master::sql()->row('SELECT * FROM company_databases WHERE company_id = :c', ['c' => $companyId]);
        if (!$d || (int)$d['is_primary'] === 1 || (int)$d['created_by_platform'] !== 1 || !DB::validName((string)$d['db_name'])) {
            return '';
        }
        if ((string)$d['db_name'] === Master::primaryDb() || (string)$d['db_name'] === Master::dbName()) {
            return '';
        }
        try {
            Sql::to(null)->exec('DROP DATABASE IF EXISTS `' . $d['db_name'] . '`');
            Master::sql()->run('DELETE FROM company_databases WHERE company_id = :c', ['c' => $companyId]);
            return 'the half-made database ' . $d['db_name'] . ' was removed';
        } catch (Throwable $e) {
            return 'the half-made database ' . $d['db_name'] . ' could not be removed: ' . $e->getMessage();
        }
    }

    /** The company's own file folders */
    public static function storage(int $companyId): void
    {
        $root = APP_ROOT . '/storage/companies';
        foreach (['', '/' . Tenant::storageKey($companyId)] as $sub) {
            if (!is_dir($root . $sub)) {
                @mkdir($root . $sub, 0775, true);
            }
            Master::protectDir($root . $sub);
        }
        foreach (['company', 'backups', 'maintenance', 'receipts', 'receipts/inbox'] as $sub) {
            @mkdir($root . '/' . Tenant::storageKey($companyId) . '/' . $sub, 0775, true);
        }
    }

    /**
     * A line in a company's own audit trail for something the platform did to it (the company's users see
     * it there; the Super Admin's name is in the platform audit log as well).
     */
    public static function audit(Sql $db, string $action, string $recordType, ?int $recordId, string $ref, string $reason, array $changes = []): void
    {
        $sa = Platform::admin();
        $db->insert('audit_trail', [
            'user_id' => null, 'username' => mb_substr('platform' . ($sa ? ':' . $sa['username'] : ''), 0, 50), 'user_roles' => 'Super Admin',
            'ip_address' => client_ip(), 'workstation' => 'Platform', 'module' => 'admin', 'page_key' => 'company.profile',
            'action' => $action, 'record_type' => $recordType, 'record_id' => $recordId, 'record_ref' => mb_substr($ref, 0, 160),
            'reason' => mb_substr($reason, 0, 255), 'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    // ------------------------------------------------------------------ users made by the platform

    /**
     * A user in a company database, created by the platform (the owner). With $admin the user gets the
     * company's full-access role (the Administrator role of the template).
     */
    public static function createUser(Sql $db, array $u, string $via, bool $admin): int
    {
        $id = $db->insert('users', [
            'username' => $u['username'], 'password_hash' => Auth::hash((string)$u['password']), 'full_name' => mb_substr(trim((string)$u['full_name']), 0, 120),
            'email' => ($u['email'] ?? '') ?: null, 'status' => 'active', 'approval_status' => 'approved', 'created_via' => $via,
            'must_change_password' => 1, 'password_changed_at' => date('Y-m-d H:i:s'),
        ]);
        if ($admin) {
            $role = $db->value('SELECT id FROM roles WHERE is_admin = 1 ORDER BY is_system DESC, id LIMIT 1');
            if (!$role) {
                throw new RuntimeException('The company has no full-access (Administrator) role to give the owner.');
            }
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => (int)$role]);
        }
        return $id;
    }

    /**
     * Makes one of the company's users its Company Owner (or creates a new owner account).
     * Returns the owner's user id.
     */
    public static function setOwner(int $companyId, ?int $userId, ?array $newOwner): int
    {
        $c = Master::company($companyId, true);
        if (!$c || !in_array($c['status'], ['active', 'inactive'], true)) {
            fail('The owner can only be set on a company that is set up.');
        }
        $db = Sql::to((string)$c['db_name']);
        if ($newOwner) {
            self::checkOwner($newOwner, $db);
            $userId = self::createUser($db, $newOwner, 'platform', true);
        } else {
            $u = $userId ? $db->row("SELECT * FROM users WHERE id = :id AND is_platform = 0", ['id' => $userId]) : null;
            if (!$u || $u['status'] !== 'active' || ($u['approval_status'] ?? 'approved') !== 'approved' || ($u['created_via'] ?? '') === 'link') {
                fail('The Company Owner has to be an active, approved user of the company (not a user of another company working there).');
            }
        }
        $old = (int)($c['owner_user_id'] ?? 0);
        Master::sql()->update('companies', ['owner_user_id' => $userId, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $companyId]);
        Membership::sync($companyId, $db);
        $name = (string)$db->value('SELECT username FROM users WHERE id = :id', ['id' => $userId]);
        self::audit($db, 'owner_changed', 'user', (int)$userId, $name,
            $newOwner ? 'New Company Owner account created by the Super Admin' : 'Made Company Owner by the Super Admin',
            [['field' => 'company_owner_user_id', 'old' => $old ?: null, 'new' => $userId]]);
        Master::forget($companyId);
        Master::log('owner_changed', $companyId, 'Company Owner set to ' . $name . ($newOwner ? ' (new account)' : '') . ($old ? " (was user #$old)" : ''));
        return (int)$userId;
    }
}
