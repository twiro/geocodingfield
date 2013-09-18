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
		
		public function __construct() {
			parent::__construct();
			
			$this->_name = __('Geocoding');
			$this->_driver = Symphony::ExtensionManager()->create('geocodingfield');
			
			// Set defaults:
			$this->set('show_column', 'yes');
			$this->set('hide', 'no');
		}
		
		public function createTable() {
			$field_id = $this->get('id');
			
			return Symphony::Database()->query("
				CREATE TABLE IF NOT EXISTS `tbl_entries_data_{$field_id}` (
					`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`entry_id` INT(11) UNSIGNED NOT NULL,
					`latitude` DOUBLE DEFAULT NULL,
					`longitude` DOUBLE DEFAULT NULL,
					PRIMARY KEY (`id`),
					KEY `entry_id` (`entry_id`),
					KEY `latitude` (`latitude`),
					KEY `longitude` (`longitude`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
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
			
			// Expression
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
			$wrapper->appendChild($div);

			// Visibility settings
			$group = new XMLElement('div');
			$group->setAttribute('class', 'two columns');
			
			$label = Widget::Label();
			$label->setAttribute('class', 'column');
			$input = Widget::Input("fields[{$order}][hide]", 'yes', 'checkbox');
			
			if ($this->get('hide') == 'yes') {
				$input->setAttribute('checked', 'checked');
			}
			
			$label->setValue($input->generate() . __(' Hide this field on publish page'));
			$group->appendChild($label);
			
			$this->appendShowColumnCheckbox($group);
			
			$wrapper->appendChild($group);
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
			
			Symphony::Database()->query("
				DELETE FROM
					`tbl_fields_{$handle}`
				WHERE
					`field_id` = '{$id}'
				LIMIT 1
			");
			
			return Symphony::Database()->insert($fields, "tbl_fields_{$handle}");
		}
		
	/*-------------------------------------------------------------------------
		Publish:
	-------------------------------------------------------------------------*/
		
		public function displayPublishPanel(XMLElement &$wrapper, $data = null, $flagWithError = null, $fieldnamePrefix = null, $fieldnamePostfix = null, $entry_id = null) {
			if (class_exists('Administration') && Administration::instance()->Page) {
				Administration::instance()->Page->addStylesheetToHead(URL . '/extensions/geocodingfield/assets/geocodingfield.publish.css', 'screen', 246);
			}
			
			$elementName = $this->get('element_name');
			
			if ($this->get('hide') != 'yes') {
				$frame = new XMLElement('div', null, array('class' => 'inline frame'));
				$map = new XMLElement(
					'img',
					null,
					array(
						'alt' => '',
						'src' => sprintf(
							'http://maps.google.com/maps/api/staticmap?zoom=7&size=180x100&sensor=false&markers=color:red|size:small|%s',
							implode(',', array($data['latitude'], $data['longitude']))
						)
				));
				$div = new XMLElement('div');
				$latitude = Widget::Label(__('Latitude'));
				$latitude->appendChild(
					Widget::Input(
						"fields{$fieldnamePrefix}[$elementName][latitude]{$fieldnamePostfix}",
						$data['latitude'], 'text', array('disabled' => 'disabled')
					)
				);
				$longitude = Widget::Label(__('Longitude'));
				$longitude->appendChild(
					Widget::Input(
						"fields{$fieldnamePrefix}[$elementName][longitude]{$fieldnamePostfix}",
						$data['longitude'], 'text', array('disabled' => 'disabled')
					)
				);
				$div->appendChild($latitude);
				$div->appendChild($longitude);
				$frame->appendChild($map);
				$frame->appendChild($div);
				$wrapper->appendChild(new XMLElement('label', $this->get('label'), array('class' => 'label')));
				$wrapper->appendChild($frame);
			} else {
				$wrapper->addClass('irrelevant');
			}
		}
		
	/*-------------------------------------------------------------------------
		Input:
	-------------------------------------------------------------------------*/
		
		public function checkPostFieldData($data, &$message, $entry_id = null) {
			$this->_driver->registerField($this);
			
			return self::__OK__;
		}
		
		public function processRawFieldData($data, &$status, &$message = null, $simulate = false, $entry_id = null) {
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
			
			if (count($this->_filter_origin['latitude']) > 0) {
				$distance = new XMLElement('distance');
				$distance->setAttribute('from', $this->_filter_origin['latitude'] . ',' . $this->_filter_origin['longitude']);
				$distance->setAttribute('distance', Extension_GeocodingField::geoDistance($this->_filter_origin['latitude'], $this->_filter_origin['longitude'], $data['latitude'], $data['longitude'], $this->_filter_origin['unit']));
				$distance->setAttribute('unit', ($this->_filter_origin['unit'] == 'k') ? 'km' : 'miles');
				$element->appendChild($distance);
			}

			$wrapper->appendChild($element);
		}
		
		public function prepareTableValue($data, XMLElement $link = null, $entry_id = null) {
			if (empty($data)) return;

			$img = sprintf(
				"<img src='http://maps.google.com/maps/api/staticmap?zoom=6&size=160x90&sensor=false&markers=color:red|size:small|%s' alt=''/>",
				implode(',', array($data['latitude'], $data['longitude']))
			);

			if ($link) {
				$link->setValue($img);
				return $link->generate();
			}

			return $img;
		}
		
	/*-------------------------------------------------------------------------
		Compile:
	-------------------------------------------------------------------------*/
		
		private function __geocodeAddress($address, $can_return_default=true) {
			$coordinates = null;

			$cache_id = md5('maplocationfield_' . $address);
			$cache = new Cacheable(Symphony::Database());
			$cachedData = $cache->check($cache_id);

			// no data has been cached
			if(!$cachedData) {

				include_once(TOOLKIT . '/class.gateway.php');

				$ch = new Gateway;
				$ch->init();
				$ch->setopt('URL', 'http://maps.googleapis.com/maps/api/geocode/json?address='.urlencode($address).'&sensor=false');
				$response = json_decode($ch->exec());

				$coordinates = $response->results[0]->geometry->location;

				if ($coordinates && is_object($coordinates)) {
					$cache->write($cache_id, $coordinates->lat . ', ' . $coordinates->lng, $this->_geocode_cache_expire); // cache lifetime in minutes
				}

			}
			// fill data from the cache
			else {
				$coordinates = $cachedData['data'];
			}

			// coordinates is an object, split and return
			if ($coordinates && is_object($coordinates)) {
				return $coordinates->lat . ', ' . $coordinates->lng;
			}
			// return comma delimeted string
			elseif ($coordinates) {
				return $coordinates;
			}
			// return default coordinates
			elseif ($return_default) {
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
			$result = Symphony::Database()->update(
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
				if (is_null($lat) || is_null($lng)) return;

				$this->_filter_origin['latitude'] = $lat;
				$this->_filter_origin['longitude'] = $lng;
				$this->_filter_origin['unit'] = $unit[0];

				// build the bounds within the query should look
				$radius = Extension_GeocodingField::geoRadius($lat, $lng, $radius, ($unit[0] == 'k'));

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
