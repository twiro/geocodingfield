<?php
	
	class Extension_GeocodingField extends Extension {

	/*-------------------------------------------------------------------------
		Definition:
	-------------------------------------------------------------------------*/
		
		protected static $fields = array();
		
		public function about() {
			return array(
				'name'			=> 'Field: Geocoding',
				'version'		=> '0.6',
				'release-date'	=> '2010-06-20',
				'author'		=> array(
					'name'			=> 'Jonas Coch',
					'website'		=> 'http://klaftertief.de/',
					'email'			=> 'jonas@klaftertief.de'
				),
				'description'	=> 'Populates fields with geocoding information using the combined values of other fields.'
			);
		}
		
		public function uninstall() {
			$this->_Parent->Database->query("DROP TABLE `tbl_fields_geocoding`");
			$this->_Parent->Configuration->remove('google-api-key', 'geocoding-field');
			$this->_Parent->saveConfig();
		}
		
		public function install() {
			$this->_Parent->Database->query("
				CREATE TABLE IF NOT EXISTS `tbl_fields_geocoding` (
					`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`field_id` INT(11) UNSIGNED NOT NULL,
					`expression` VARCHAR(255) DEFAULT NULL,
					`hide` ENUM('yes', 'no') DEFAULT 'no',
					PRIMARY KEY (`id`),
					KEY `field_id` (`field_id`)
				)
			");
			
			return true;
		}
		
		public function getSubscribedDelegates() {
			return array(
				array(
					'page'		=> '/blueprints/events/new/',
					'delegate'	=> 'AppendEventFilter',
					'callback'	=> 'appendEventFilter'
				),
				array(
					'page'		=> '/blueprints/events/edit/',
					'delegate'	=> 'AppendEventFilter',
					'callback'	=> 'appendEventFilter'
				),
				array(
					'page'		=> '/system/preferences/',
					'delegate'	=> 'AddCustomPreferenceFieldsets',
					'callback'	=> 'addCustomPreferenceFieldsets'
				),
				array(
					'page'		=> '/publish/new/',
					'delegate'	=> 'EntryPostCreate',
					'callback'	=> 'compileBackendFields'
				),
				array(
					'page'		=> '/publish/edit/',
					'delegate'	=> 'EntryPostEdit',
					'callback'	=> 'compileBackendFields'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'EventPostSaveFilter',
					'callback'	=> 'compileFrontendFields'
				),
			);
		}
		
	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/
		
		public function getMapsAPI() {
			return $this->_Parent->Configuration->get('google-api-key', 'geocoding-field');
		}
				
		public function getXPath($entry) {
			$entry_xml = new XMLElement('entry');
			$section_id = $entry->_fields['section_id'];
			$data = $entry->getData(); $fields = array();
			
			$entry_xml->setAttribute('id', $entry->get('id'));
			
			$associated = $entry->fetchAllAssociatedEntryCounts();
			
			if (is_array($associated) and !empty($associated)) {
				foreach ($associated as $section => $count) {
					$handle = $this->_Parent->Database->fetchVar('handle', 0, "
						SELECT
							s.handle
						FROM
							`tbl_sections` AS s
						WHERE
							s.id = '{$section}'
						LIMIT 1
					");
					
					$entry_xml->setAttribute($handle, (string)$count);
				}
			}
			
			// Add fields:
			foreach ($data as $field_id => $values) {
				if (empty($field_id)) continue;
				
				$field =& $entry->_Parent->fieldManager->fetch($field_id);
				$field->appendFormattedElement($entry_xml, $values, false);
			}
			
			$xml = new XMLElement('data');
			$xml->appendChild($entry_xml);
			
			$dom = new DOMDocument();
			$dom->strictErrorChecking = false;
			$dom->loadXML($xml->generate(true));
			
			$xpath = new DOMXPath($dom);
			
			if (version_compare(phpversion(), '5.3', '>=')) {
				$xpath->registerPhpFunctions();
			}
			
			return $xpath;
		}
		
		/*
			Modified from:
			http://www.kevinbradwick.co.uk/developer/php/free-to-script-to-calculate-the-radius-of-a-coordinate-using-latitude-and-longitude
		*/
		public function geoRadius($lat, $lng, $rad, $kilometers=false) {
			$radius = ($kilometers) ? ($rad * 0.621371192) : $rad;
			
			(float)$dpmLAT = 1 / 69.1703234283616; 

			// Latitude calculation
			(float)$usrRLAT = $dpmLAT * $radius;
			(float)$latMIN = $lat - $usrRLAT;
			(float)$latMAX = $lat + $usrRLAT;

			// Longitude calculation
			(float)$mpdLON = 69.1703234283616 * cos($lat * (pi/180));
			(float)$dpmLON = 1 / $mpdLON; // degrees per mile longintude
			$usrRLON = $dpmLON * $radius;
			
			$lonMIN = $lng - $usrRLON;
			$lonMAX = $lng + $usrRLON;
			
			return array("lonMIN" => $lonMIN, "lonMAX" => $lonMAX, "latMIN" => $latMIN, "latMAX" => $latMAX);
		}

		/*
		Calculate distance between two lat/long pairs
		*/
		public function geoDistance($lat1, $lon1, $lat2, $lon2, $unit) { 

			$theta = $lon1 - $lon2; 
			$dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta)); 
			$dist = acos($dist); 
			$dist = rad2deg($dist); 
			$miles = $dist * 60 * 1.1515;

			$unit = strtolower($unit);
			
			$distance = 0;
			
			if ($unit == "k") {
				$distance = ($miles * 1.609344); 
			} else if ($unit == "n") {
				$distance = ($miles * 0.8684);
			} else {
				$distance = $miles;
			}
			
			return round($distance, 1);
			
		}

	/*-------------------------------------------------------------------------
		Delegates:
	-------------------------------------------------------------------------*/
		
		public function addCustomPreferenceFieldsets($context) {
			$group = new XMLElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(
				new XMLElement('legend', 'Geocoding Field')
			);
			
			$label = Widget::Label('Google Maps API Key');
			$label->appendChild(Widget::Input(
				'settings[geocoding-field][google-api-key]', General::Sanitize($this->getMapsAPI())
			));
			$group->appendChild($label);
			
			$group->appendChild(new XMLElement('p', 'Get a Google Maps API key from the <a href="http://code.google.com/apis/maps/index.html">Google Maps site</a>.', array('class' => 'help')));

			$context['wrapper']->appendChild($group);
		}
		
		public function appendEventFilter($context) {
			$context['options'][] = array(
				'geocoding',
				@in_array(
					'geocoding', $context['selected']
				),
				'Geocoding'
			);
		}
		
	/*-------------------------------------------------------------------------
		Fields:
	-------------------------------------------------------------------------*/

		public function registerField($field) {
			self::$fields[] = $field;
		}

		public function compileBackendFields($context) {
			foreach (self::$fields as $field) {
				$field->compile($context['entry']);
			}
		}

		public function compileFrontendFields($context) {
			foreach (self::$fields as $field) {
				$field->compile($context['entry']);
			}
		}

	}
	
?>