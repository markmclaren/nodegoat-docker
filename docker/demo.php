<?php

/**
 * 'Hello nodegoat' quickstart: a small, fictional correspondence network
 * (the example used by the nodegoat Guides: City, Person, Letter).
 *
 * Prepended (auto_prepend_file) to the 1100CC CLI entry point, like docker-setup.php:
 *   php index.php 'SIGNATURE' docker_demo run
 * It uses the same nodegoat classes as the nodegoat API (/model and /data PUT):
 * StoreType, StoreTypeObjects and StoreCustomProject. Skipped when the project already exists.
 */

class docker_demo {

	const PROJECT_NAME = 'Hello nodegoat';

	// Real cities and coordinates [latitude, longitude]
	const CITIES = [
		'Amsterdam' => [52.3676, 4.9041],
		'London' => [51.5072, -0.1276],
		'Paris' => [48.8566, 2.3522],
		'Berlin' => [52.5200, 13.4050],
		'Vienna' => [48.2082, 16.3738],
		'Edinburgh' => [55.9533, -3.1883],
	];

	// Fictional people: occupation, [city, from, to] residences
	const PEOPLE = [
		'Anna de Wit' => ['Printer', [['Amsterdam', '1740', '1759'], ['London', '1759', '1770']]],
		'Thomas Hale' => ['Natural philosopher', [['Edinburgh', '1745', '1770']]],
		'Claire Moreau' => ['Salon host', [['Paris', '1740', '1770']]],
		'Friedrich Lang' => ['Mathematician', [['Berlin', '1748', '1762'], ['Vienna', '1762', '1770']]],
		'Maria Kessler' => ['Botanist', [['Vienna', '1745', '1770']]],
	];

	// Fictional letters: sender, receiver, sent date, received date, summary
	const LETTERS = [
		['Anna de Wit', 'Claire Moreau', '1751-03-02', '1751-03-11', 'Offers to print the salon\'s collected essays.'],
		['Claire Moreau', 'Anna de Wit', '1751-04-05', '1751-04-14', 'Accepts, and sends the first manuscripts.'],
		['Thomas Hale', 'Friedrich Lang', '1752-06-18', '1752-07-09', 'Asks for help with a problem in celestial mechanics.'],
		['Friedrich Lang', 'Thomas Hale', '1752-08-01', '1752-08-24', 'Sends a solution and a new question.'],
		['Maria Kessler', 'Claire Moreau', '1753-02-10', '1753-02-25', 'Describes a collection of alpine plants.'],
		['Claire Moreau', 'Thomas Hale', '1753-05-20', '1753-06-08', 'Introduces Maria Kessler\'s botanical work.'],
		['Thomas Hale', 'Maria Kessler', '1753-07-15', '1753-08-12', 'Requests seed samples for the Edinburgh garden.'],
		['Anna de Wit', 'Friedrich Lang', '1755-01-09', '1755-01-20', 'Proposes printing his lectures on geometry.'],
		['Friedrich Lang', 'Maria Kessler', '1756-09-30', '1756-10-08', 'Plans a visit to Vienna.'],
		['Anna de Wit', 'Thomas Hale', '1760-02-14', '1760-02-22', 'Announces her move to London.'],
		['Friedrich Lang', 'Claire Moreau', '1763-04-03', '1763-04-15', 'Writes from his new post in Vienna.'],
		['Maria Kessler', 'Anna de Wit', '1764-11-11', '1764-11-29', 'Sends illustrations for a printed herbarium.'],
	];

	public static function run() {

		$res = DB::query("SELECT id FROM ".DB::getTable('DEF_NODEGOAT_CUSTOM_PROJECTS')." WHERE name = '".DBFunctions::strEscape(self::PROJECT_NAME)."'");

		if ($res->getRowCount()) {
			self::log('project exists, skipped');
			return;
		}

		$user_id = self::getOwnerUserID();

		if (!$user_id) {
			self::log('FAILED no nodegoat user to own the data');
			return;
		}

		$arr_type_ids = self::storeModel($user_id);
		self::storeData($arr_type_ids, $user_id);
		self::storeProject($arr_type_ids);

		self::log('project \''.self::PROJECT_NAME.'\' created: '.count(self::CITIES).' cities, '.count(self::PEOPLE).' people, '.count(self::LETTERS).' letters');
	}

	private static function getOwnerUserID() {

		$str_username = getenv('NODEGOAT_USER');

		$res = DB::query("SELECT id FROM ".DB::getTable('TABLE_USERS')."
			".($str_username ? "ORDER BY uname = '".DBFunctions::strEscape($str_username)."' DESC, id" : "ORDER BY id")."
			LIMIT 1
		");
		$arr_row = $res->fetchAssoc();

		return ($arr_row ? (int)$arr_row['id'] : false);
	}

	// Same as a PUT to /model: Types reference each other by name ('City', 'Location'), resolved in a second pass
	private static function storeModel($user_id) {

		$func_location_ref = function($str_name, $has_period) {

			return ['object_sub_details' => [
				'object_sub_details_name' => $str_name,
				'object_sub_details_is_single' => !$has_period,
				'object_sub_details_has_date' => true,
				'object_sub_details_is_date_period' => $has_period,
				'object_sub_details_has_location' => true,
				'object_sub_details_location_ref_type_id' => 'City',
				'object_sub_details_location_ref_type_id_locked' => true,
				'object_sub_details_location_ref_object_sub_details_id' => 'Location',
				'object_sub_details_location_ref_object_sub_details_id_locked' => true
			], 'object_sub_descriptions' => []];
		};

		$arr_types = [
			'City' => [
				'type' => ['name' => 'City', 'color' => '#2a9d8f', 'use_object_name' => true, 'object_name_in_overview' => true],
				'definitions' => [],
				'object_descriptions' => [],
				'object_sub_details' => [
					['object_sub_details' => [
						'object_sub_details_name' => 'Location',
						'object_sub_details_is_single' => true,
						'object_sub_details_has_date' => false,
						'object_sub_details_has_location' => true,
						'object_sub_details_location_setting' => 'point'
					], 'object_sub_descriptions' => []]
				]
			],
			'Person' => [
				'type' => ['name' => 'Person', 'color' => '#e76f51', 'use_object_name' => true, 'object_name_in_overview' => true],
				'definitions' => [],
				'object_descriptions' => [
					['object_description_name' => 'Occupation', 'object_description_value_type_base' => 'text', 'object_description_in_search' => true, 'object_description_in_overview' => true]
				],
				'object_sub_details' => [
					$func_location_ref('Residence', true)
				]
			],
			'Letter' => [
				'type' => ['name' => 'Letter', 'color' => '#264653', 'use_object_name' => false, 'object_name_in_overview' => true],
				'definitions' => [],
				'object_descriptions' => [
					['object_description_name' => 'Sender', 'object_description_value_type_base' => 'type', 'object_description_ref_type_id' => 'Person', 'object_description_in_name' => true, 'object_description_in_search' => true, 'object_description_in_overview' => true],
					['object_description_name' => 'Receiver', 'object_description_value_type_base' => 'type', 'object_description_ref_type_id' => 'Person', 'object_description_in_name' => true, 'object_description_in_search' => true, 'object_description_in_overview' => true],
					['object_description_name' => 'Summary', 'object_description_value_type_base' => 'text', 'object_description_in_overview' => true]
				],
				'object_sub_details' => [
					$func_location_ref('Sent', false),
					$func_location_ref('Received', false)
				]
			]
		];

		$arr_type_ids = [];
		$arr_resolve = [];

		DB::startTransaction('docker_demo_model');

		try {

			foreach ($arr_types as $str_name => $arr_type) {

				$store_type = new StoreType(false, $user_id);
				$type_id = $store_type->store($arr_type['type'], $arr_type['definitions'], $arr_type['object_descriptions'], $arr_type['object_sub_details']);

				$arr_type_ids[$str_name] = $type_id;

				if ($store_type->hasUnresolvedIDs()) {
					$arr_resolve[$type_id] = $arr_type;
				}
			}

			foreach ($arr_resolve as $type_id => $arr_type) {

				$store_type = new StoreType($type_id, $user_id);
				$store_type->setMode(StoreType::MODE_UPDATE);
				$store_type->store($arr_type['type'], $arr_type['definitions'], $arr_type['object_descriptions'], $arr_type['object_sub_details']);
			}

			StoreType::setTypesObjectPaths();
		} catch (Exception $e) {

			DB::rollbackTransaction('docker_demo_model');
			throw($e);
		}

		DB::commitTransaction('docker_demo_model');

		self::log('model: '.implode(', ', array_keys($arr_type_ids)));

		return $arr_type_ids;
	}

	// Same as a PUT to /data/type/ID
	private static function storeData($arr_type_ids, $user_id) {

		$arr_ids = [];

		foreach (['City', 'Person', 'Letter'] as $str_type) {

			$type_id = $arr_type_ids[$str_type];
			$arr_type_set = StoreType::getTypeSet($type_id);

			$arr_descriptions = [];
			foreach ($arr_type_set['object_descriptions'] as $object_description_id => $arr_object_description) {
				$arr_descriptions[$arr_object_description['object_description_name']] = $object_description_id;
			}
			$arr_subs = [];
			foreach ($arr_type_set['object_sub_details'] as $object_sub_details_id => $arr_object_sub_details) {
				$arr_subs[$arr_object_sub_details['object_sub_details']['object_sub_details_name']] = $object_sub_details_id;
			}

			$arr_objects = [];

			if ($str_type == 'City') {

				foreach (self::CITIES as $str_name => list($latitude, $longitude)) {
					$arr_objects[$str_name] = [
						'object' => ['object_name_plain' => $str_name],
						'object_definitions' => [],
						'object_subs' => [
							['object_sub' => ['object_sub_details_id' => $arr_subs['Location'], 'object_sub_location_type' => 'point', 'object_sub_location_latitude' => $latitude, 'object_sub_location_longitude' => $longitude], 'object_sub_definitions' => []]
						]
					];
				}
			} else if ($str_type == 'Person') {

				foreach (self::PEOPLE as $str_name => list($str_occupation, $arr_residences)) {

					$arr_object_subs = [];
					foreach ($arr_residences as list($str_city, $str_from, $str_to)) {
						$arr_object_subs[] = ['object_sub' => [
							'object_sub_details_id' => $arr_subs['Residence'],
							'object_sub_date_start' => $str_from,
							'object_sub_date_end' => $str_to,
							'object_sub_location_type' => 'reference',
							'object_sub_location_ref_object_id' => $arr_ids['City'][$str_city]
						], 'object_sub_definitions' => []];
					}

					$arr_objects[$str_name] = [
						'object' => ['object_name_plain' => $str_name],
						'object_definitions' => [
							['object_description_id' => $arr_descriptions['Occupation'], 'object_definition_value' => $str_occupation]
						],
						'object_subs' => $arr_object_subs
					];
				}
			} else {

				$func_city = function($str_person, $str_date) {

					foreach (self::PEOPLE[$str_person][1] as list($str_city, $str_from, $str_to)) {
						if (substr($str_date, 0, 4) >= $str_from && substr($str_date, 0, 4) < $str_to) {
							return $str_city;
						}
					}

					return self::PEOPLE[$str_person][1][0][0];
				};

				foreach (self::LETTERS as $i => list($str_sender, $str_receiver, $str_sent, $str_received, $str_summary)) {
					$arr_objects[$i] = [
						'object' => [],
						'object_definitions' => [
							['object_description_id' => $arr_descriptions['Sender'], 'object_definition_ref_object_id' => $arr_ids['Person'][$str_sender]],
							['object_description_id' => $arr_descriptions['Receiver'], 'object_definition_ref_object_id' => $arr_ids['Person'][$str_receiver]],
							['object_description_id' => $arr_descriptions['Summary'], 'object_definition_value' => $str_summary]
						],
						'object_subs' => [
							['object_sub' => ['object_sub_details_id' => $arr_subs['Sent'], 'object_sub_date_start' => $str_sent, 'object_sub_location_type' => 'reference', 'object_sub_location_ref_object_id' => $arr_ids['City'][$func_city($str_sender, $str_sent)]], 'object_sub_definitions' => []],
							['object_sub' => ['object_sub_details_id' => $arr_subs['Received'], 'object_sub_date_start' => $str_received, 'object_sub_location_type' => 'reference', 'object_sub_location_ref_object_id' => $arr_ids['City'][$func_city($str_receiver, $str_received)]], 'object_sub_definitions' => []]
						]
					];
				}
			}

			$storage = new StoreTypeObjects($type_id, false, $user_id);
			$storage->setMode(null, false);

			DB::startTransaction('docker_demo_data');

			try {

				foreach ($arr_objects as $key => $arr_object) {

					$storage->setObjectID(false);
					$arr_ids[$str_type][$key] = $storage->store($arr_object['object'], $arr_object['object_definitions'], $arr_object['object_subs']);
				}

				$storage->save();
				$storage->commit(true);
			} catch (Exception $e) {

				DB::rollbackTransaction('docker_demo_data');
				throw($e);
			}

			$storage->touch();

			DB::commitTransaction('docker_demo_data');

			self::log('data: '.count($arr_objects).' '.$str_type.' objects');
		}

		return $arr_ids;
	}

	// Project with the three Types, available to every nodegoat user
	private static function storeProject($arr_type_ids) {

		$store_project = new StoreCustomProject(false);
		$project_id = $store_project->store(['name' => self::PROJECT_NAME, 'types' => array_values($arr_type_ids), 'visual_settings_id' => 0]);

		DB::query("INSERT INTO ".DB::getTable('USER_LINK_NODEGOAT_CUSTOM_PROJECTS')."
			(user_id, project_id, is_active)
			SELECT id, ".(int)$project_id.", TRUE FROM ".DB::getTable('TABLE_USERS')."
		");

		return $project_id;
	}

	private static function log($str) {

		fwrite(STDOUT, '[docker-demo] '.$str.PHP_EOL);
	}
}
