<?php

/**
 * Container first-run setup for 1100CC/nodegoat.
 *
 * Prepended (auto_prepend_file) to the 1100CC CLI entry point by web-entrypoint.sh:
 *   php index.php 'SIGNATURE' docker_setup run
 * so 1100CC is fully bootstrapped when run() is called. Safe to run on every start.
 */

class docker_setup {

	public static function run() {

		self::runSetup();
		self::seedCoreAdmin();
		self::seedNodegoatUser();
		self::scheduleJobs();
	}

	// Same as CMS > Admin > Setup 1100CC (cms_admin::runSetup), without the 'transform' steps
	private static function runSetup() {

		foreach (getModuleConfiguration('setupProperties') as $module => $arr_settings) {
			foreach ($arr_settings as $str_name => $arr_setup) {

				if (empty($arr_setup['run']) || !empty($arr_setup['transform'])) {
					continue;
				}

				try {
					$arr_setup['run']();
					self::log('setup '.$str_name.': done');
				} catch (Throwable $e) {
					self::log('setup '.$str_name.': FAILED '.$e->getMessage());
				}
			}
		}
	}

	// CMS login (CORE Admin)
	private static function seedCoreAdmin() {

		$str_username = getenv('CMS_ADMIN_USER');
		$str_password = getenv('CMS_ADMIN_PASSWORD');

		if (!$str_username || !$str_password) {
			return;
		}

		$res = DB::query("SELECT id FROM ".DB::getTable('TABLE_CORE_USERS')." WHERE uname = '".DBFunctions::strEscape($str_username)."'");

		if ($res->getRowCount()) {
			self::log('CMS admin '.$str_username.': exists');
			return;
		}

		DB::query("INSERT INTO ".DB::getTable('TABLE_CORE_USERS')."
			(name, uname, lang_code, email, img, biography, passhash, labeler)
				VALUES
			('CORE Admin', '".DBFunctions::strEscape($str_username)."', '', '', '', '', '".DBFunctions::strEscape(generateHash($str_password))."', TRUE)
		");

		self::log('CMS admin '.$str_username.': created');
	}

	// nodegoat login: 'User' group, nodegoat Admin clearance, all cleared (Management/Model) pages
	private static function seedNodegoatUser() {

		$str_username = getenv('NODEGOAT_USER');
		$str_password = getenv('NODEGOAT_PASSWORD');
		$str_email = (getenv('NODEGOAT_EMAIL') ?: $str_username.'@nodegoat.localhost');

		if (!$str_username || !$str_password) {
			return;
		}

		$res = DB::query("SELECT id FROM ".DB::getTable('TABLE_USERS')." WHERE uname = '".DBFunctions::strEscape($str_username)."'");

		if ($res->getRowCount()) {
			self::log('nodegoat user '.$str_username.': exists');
			return;
		}

		$res = DB::query("SELECT id FROM ".DB::getTable('TABLE_USER_GROUPS')." WHERE name = 'User' LIMIT 1");
		$arr_group = $res->fetchAssoc();

		if (!$arr_group) {
			self::log('nodegoat user '.$str_username.': FAILED no \'User\' group');
			return;
		}

		DB::query("INSERT INTO ".DB::getTable('TABLE_USERS')."
			(enabled, group_id, name, uname, email, parent_id, passhash)
				VALUES
			(TRUE, ".(int)$arr_group['id'].", '".DBFunctions::strEscape($str_username)."', '".DBFunctions::strEscape($str_username)."', '".DBFunctions::strEscape($str_email)."', 0, '".DBFunctions::strEscape(generateHash($str_password))."')
		");

		$user_id = (int)DB::lastInsertID();

		DB::query("INSERT INTO ".DB::getTable('TABLE_USER_DETAILS')."
			(user_id, clearance)
				VALUES
			(".$user_id.", ".NODEGOAT_CLEARANCE_ADMIN.")
		");

		DB::query("INSERT INTO ".DB::getTable('TABLE_USER_PAGE_CLEARANCE')."
			(user_id, page_id)
			SELECT ".$user_id.", id FROM ".DB::getTable('TABLE_PAGES')." WHERE clearance = 1
		");

		self::log('nodegoat user '.$str_username.': created');
	}

	// Same as CMS > Jobs > run; the next web request starts the Jobs process (cms_jobs::callJobs)
	private static function scheduleJobs() {

		// A fresh container runs no processes; stale PIDs from a previous container could be reused and look alive
		DB::query("UPDATE ".DB::getTable('TABLE_SITE_JOBS_TIMER')." SET process_id = NULL");
		DB::query("UPDATE ".DB::getTable('TABLE_SITE_JOBS')." SET process_id = NULL");

		if (getenv('JOBS_AUTOSTART') === '0') {
			return;
		}

		DB::query("INSERT INTO ".DB::getTable('TABLE_SITE_JOBS_TIMER')."
			(date)
				VALUES
			(".DBFunctions::dateTimeNow().")
			".DBFunctions::onConflict('unique_row', false, 'unique_row = TRUE')."
		");

		self::log('jobs: scheduled');
	}

	private static function log($str) {

		fwrite(STDOUT, '[docker-setup] '.$str.PHP_EOL);
	}
}
