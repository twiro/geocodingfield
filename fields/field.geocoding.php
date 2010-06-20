<?php
	
	if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');
	
	require_once(CORE . '/class.cacheable.php');

	class FieldGeocoding extends Field {
		protected $_driver = null;
		protected static $ready = true;
		
		private $_geocode_cache_expire = 60; // minutes
		private $_filter_origin = array();
		
	/*-------------------------------------------------------------------------
		Definition:
	-------------------------------------------------------------------------*/
		
		public function __construct(&$parent) {
			parent::__construct($parent);
			
			$this->_name = __('Geocoding');
			$this->_driver = $this->_engine->ExtensionManager->create('geocodingfield');
			
			// Set defaults:
			$this->set('show_column', 'yes');
			$this->set('hide', 'no');
		}
		
		public function createTable() {
			$field_id = $this->get('id');
			
			return $this->_engine->Database->query("
				CREATE TABLE IF NOT EXISTS `tbl_entries_data_{$field_id}` (
					`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`entry_id` INT(11) UNSIGNED NOT NULL,
					`latitude` DOUBLE DEFAULT NULL,
					`longitude` DOUBLE DEFAULT NULL,
					PRIMARY KEY (`id`),
					KEY `entry_id` (`entry_id`),
					KEY `latitude` (`latitude`),
					KEY `longitude` (`longitude`)
				)
			");
		}
		
		public function canFilter() {
			return true;
		}
		
	/*-------------------------------------------------------------------------
		Settings:
	-------------------------------------------------------------------------*/
		
		public function displaySettingsPanel(&$wrapper, $errors = null) {
			parent::displaySettingsPanel($wrapper, $errors);
			
			$order = $this->get('sortorder');
			
		/*---------------------------------------------------------------------
			Expression
		---------------------------------------------------------------------*/
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$div = new XMLElement('div');
			$label = Widget::Label(__('Expression'));
			$label->appendChild(Widget::Input(
				"fields[{$order}][expression]",
				$this->get('expression')
			));
			
			$help = new XMLElement('p');
			$help->setAttribute('class', 'help');
			
			$help->setValue(__('To access the other fields, use XPath: <code>{entry/field-one} static text {entry/field-two}</code>.'));
			
			$div->appendChild($label);
			$div->appendChild($help);
			$group->appendChild($div);
			$wrapper->appendChild($group);
			
		/*---------------------------------------------------------------------
			Hide input
		---------------------------------------------------------------------*/
			
			$label = Widget::Label();
			$input = Widget::Input("fields[{$order}][hide]", 'yes', 'checkbox');
			
			if ($this->get('hide') == 'yes') {
				$input->setAttribute('checked', 'checked');
			}
			
			$label->setValue($input->generate() . __(' Hide this field on publish page'));
			$wrapper->appendChild($label);
			
			$this->appendShowColumnCheckbox($wrapper);
		}
		
		public function commit() {
			if (!parent::commit()) return false;
			
			$id = $this->get('id');
			$handle = $this->handle();
			
			if ($id === false) return false;
			
			$fields = array(
				'field_id'			=> $id,
				'expression'		=> $this->get('expression'),
				'hide'				=> $this->get('hide')
			);
			
			$this->Database->query("
				DELETE FROM
					`tbl_fields_{$handle}`
				WHERE
					`field_id` = '{$id}'
				LIMIT 1
			");
			
			return $this->Database->insert($fields, "tbl_fields_{$handle}");
		}
		
	/*-------------------------------------------------------------------------
		Publish:
	-------------------------------------------------------------------------*/
		
		public function displayPublishPanel(&$wrapper, $data = null, $flagWithError = null, $prefix = null, $postfix = null) {
			$sortorder = $this->get('sortorder');
			$element_name = $this->get('element_name');
			
			if ($this->get('hide') != 'yes') {
				$value = '';
				if (!empty($data)) {
					$value = 'lat: '.$data['latitude'].', lng: '.$data['longitude'];
				}
				$label = Widget::Label($this->get('label'));
				$label->appendChild(
					Widget::Input(
						"fields{$prefix}[$element_name]{$postfix}",
						$value, 'text', array('disabled' => 'disabled')
					)
				);
				$wrapper->appendChild($label);
			}
		}
		
	/*-------------------------------------------------------------------------
		Input:
	-------------------------------------------------------------------------*/
		
		public function checkPostFieldData($data, &$message, $entry_id = null) {
			$this->_driver->registerField($this);
			
			return self::__OK__;
		}
		
		public function processRawFieldData($data, &$status, $simulate = false, $entry_id = null) {
			$status = self::__OK__;
			
			return array(
				'latitude' => null,
				'longitude' => null
			);
			
		}
		
	/*-------------------------------------------------------------------------
		Output:
	-------------------------------------------------------------------------*/
		
		public function appendFormattedElement(&$wrapper, $data, $encode = false) {
			if (!self::$ready) return;
			
			$element = new XMLElement($this->get('element_name'), null, array(
				'latitude' => $data['latitude'],
				'longitude' => $data['longitude'],
			));
			
			$wrapper->appendChild($element);
		}
		
		public function prepareTableValue($data, XMLElement $link = null) {
			if (empty($data)) return;
			
			return parent::prepareTableValue(
				array(
					'value' => 'lat: '.$data['latitude'].', lng: '.$data['longitude']
				), $link
			);
		}
		
	/*-------------------------------------------------------------------------
		Compile:
	-------------------------------------------------------------------------*/
		
		private function __geocodeAddress($address) {

			$coordinates = null;

			$cache_id = md5('maplocationfield_' . $address);
			$cache = new Cacheable($this->_engine->Database);
			$cachedData = $cache->check($cache_id);	

			// no data has been cached
			if(!$cachedData) {

				include_once(TOOLKIT . '/class.gateway.php'); 

				$ch = new Gateway;
				$ch->init();
				$ch->setopt('URL', 'http://maps.google.com/maps/geo?q='.urlencode($address).'&output=json&key='.$this->_engine->Configuration->get('google-api-key', 'geocoding-field'));
				$response = json_decode($ch->exec());

				$coordinates = $response->Placemark[0]->Point->coordinates;

				if ($coordinates && is_array($coordinates)) {
					$cache->write($cache_id, $coordinates[1] . ', ' . $coordinates[0], $this->_geocode_cache_expire); // cache lifetime in minutes
				}

			}
			// fill data from the cache
			else {		
				$coordinates = $cachedData['data'];
			}

			// coordinates is an array, split and return
			if ($coordinates && is_array($coordinates)) {
				return $coordinates[1] . ', ' . $coordinates[0];
			}
			// return comma delimeted string
			elseif ($coordinates) {
				return $coordinates;
			}
			// return default coordinates
			else {
				return '0, 0';
			}
		}
	
		public function compile($entry) {
			self::$ready = false;
			
			$xpath = $this->_driver->getXPath($entry);
			
			$entry_id = $entry->get('id');
			$field_id = $this->get('id');
			$expression = $this->get('expression');
			$replacements = array();
			
			// Find queries:
			preg_match_all('/\{[^\}]+\}/', $expression, $matches);
			
			// Find replacements:
			foreach ($matches[0] as $match) {
				$result = @$xpath->evaluate('string(' . trim($match, '{}') . ')');
				
				if (!is_null($result)) {
					$replacements[$match] = trim($result);
				}
				
				else {
					$replacements[$match] = '';
				}
			}
			
			// Apply replacements:
			$value = str_replace(
				array_keys($replacements),
				array_values($replacements),
				$expression
			);
			
			$geocode = $this->__geocodeAddress($value);
			if ($geocode) $geocode = explode(',', $geocode);
			$lat = trim($geocode[0]);
			$lng = trim($geocode[1]);
			
			self::$ready = true;
			
			// Save:
			$result = $this->Database->update(
				array(
					'latitude'	=> $lat,
					'longitude'	=> $lng
				),
				"tbl_entries_data_{$field_id}",
				"`entry_id` = '{$entry_id}'"
			);
		}
		
	/*-------------------------------------------------------------------------
		Filtering:
	-------------------------------------------------------------------------*/
		
	function buildDSRetrivalSQL($data, &$joins, &$where, $andOperation=false){
		
		// Symphony by default splits filters by commas. We want commas, so 
		// concatenate filters back together again putting commas back in
		$data = join(',', $data);
		
		/*
		within 20 km of 10.545, -103.1
		within 2km of 1 West Street, Burleigh Heads
		within 500 miles of England
		*/
		
		// is a "within" radius filter
		if(preg_match('/^within/i', $data)){
			$field_id = $this->get('id');

			// parse out individual filter parts
			preg_match('/^within ([0-9]+)\s?(km|mile|miles) of (.+)$/', $data, $filters);

			$radius = trim($filters[1]);
			$unit = strtolower(trim($filters[2]));
			$origin = trim($filters[3]);
			
			$lat = null;
			$lng = null;
			
			// is a lat/long pair
			if (preg_match('/^(-?[.0-9]+),\s?(-?[.0-9]+)$/', $origin, $latlng)) {
				$lat = $latlng[1];
				$lng = $latlng[2];
			}
			// otherwise the origin needs geocoding
			else {
				$geocode = $this->__geocodeAddress($origin);
				if ($geocode) $geocode = explode(',', $geocode);
				$lat = trim($geocode[0]);
				$lng = trim($geocode[1]);
			}
			
			// if we don't have a decent set of coordinates, we can't query
			if (is_null($lat) || is_null($lng)) return true;
			
			$this->_filter_origin['latitude'] = $lat;
			$this->_filter_origin['longitude'] = $lng;
			$this->_filter_origin['unit'] = $unit[0];
			
			// build the bounds within the query should look
			$radius = $this->_driver->geoRadius($lat, $lng, $radius, ($unit[0] == 'k'));
			
			$where .= sprintf(
				" AND `t%d`.`latitude` BETWEEN %s AND %s AND `t%d`.`longitude` BETWEEN %s AND %s",
				$field_id, $radius['latMIN'], $radius['latMAX'],
				$field_id, $radius['lonMIN'], $radius['lonMAX']
			);
			
			$joins .= " LEFT JOIN `tbl_entries_data_$field_id` AS `t$field_id` ON (`e`.`id` = `t$field_id`.entry_id) ";
			
		}
		
		return true;
		
	}
		
	}
	
?>