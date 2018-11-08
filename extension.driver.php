<?php

class Extension_GeocodingField extends Extension
{

    /*------------------------------------------------------------------------*/
    /*  DEFINITION
    /*------------------------------------------------------------------------*/

    protected static $fields = array();

    /**
     * Name of the extension field table
     * @var string
     *
     * @since version 2.0.0
     */

    const FIELD_TBL_NAME = 'tbl_fields_geocoding';

    /**
     * INSTALL
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/extension/#install
     *
     * @since version 1.0.0
     */

    public function install()
    {
        return self::createFieldTable();
    }

    /**
     * CREATE FIELD TABLE
     *
     * @since version 2.0.0
     */

    public static function createFieldTable()
    {
        $tbl = self::FIELD_TBL_NAME;

        return Symphony::Database()->query("
            CREATE TABLE IF NOT EXISTS `$tbl` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `field_id` INT(11) UNSIGNED NOT NULL,
                `expression` VARCHAR(255) DEFAULT NULL,
                `hide` ENUM('yes', 'no') DEFAULT 'no',
                PRIMARY KEY (`id`),
                KEY `field_id` (`field_id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ");
    }

    /**
     * UNINSTALL
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/extension/#uninstall
     *
     * @since version 1.0.0
     */

    public function uninstall()
    {
        return self::deleteFieldTable();
    }

    /**
     * DELETE FIELD TABLE
     *
     * @since version 2.0.0
     */

    public static function deleteFieldTable()
    {
        $tbl = self::FIELD_TBL_NAME;

        return Symphony::Database()->query("
            DROP TABLE IF EXISTS `$tbl`
        ");
    }

    /**
     * UPDATE
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/extension/#update
     *
     * @since version 1.0.0
     */

    public function update($previousVersion = false)
    {
        // Add new default configuration settings when updating from version 1.X or 0.X
        if (version_compare($previousVersion, '2.0', '<')) {
            Symphony::Configuration()->setArray(
                array(
                    'geocoding' => array(
                        'google_api_key' => '',
                    )
                ), false
            );
            Symphony::Configuration()->write();
        }

        // Remove old configuration settings
        if (version_compare($previousVersion, '0.7', '<')) {
            Symphony::Configuration()->remove('geocoding-field');
            Symphony::Configuration()->write();
        }
        return true;
    }

    /**
     * GET SUBSCRIBED DELEGATES
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/extension/#getSubscribedDelegates
     *
     * @since version 1.0.0
     */

    public function getSubscribedDelegates()
    {
        return array(
            array(
                'page' => '/backend/',
                'delegate' => 'InitaliseAdminPageHead',
                'callback' => 'appendResources'
            ),
            array(
                'page'     => '/system/preferences/',
                'delegate' => 'AddCustomPreferenceFieldsets',
                'callback' => 'addCustomPreferenceFieldsets'
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

    /*------------------------------------------------------------------------*/
    /*  COMPILE
    /*------------------------------------------------------------------------*/

    public function compileBackendFields($context)
    {
        foreach (self::$fields as $field) {
            $field->compile($context['entry']);
        }
    }

    public function compileFrontendFields($context)
    {
        foreach (self::$fields as $field) {
            $field->compile($context['entry']);
        }
    }

    /*------------------------------------------------------------------------*/
    /*  PREFERENCES
    /*------------------------------------------------------------------------*/

    /**
     * ADD CUSTOM PREFERENCE FIELDSETS
     *
     * Add configuration settings to symphony's preferences page
     *
     * @since version 2.0.0
     */

    public function addCustomPreferenceFieldsets($context)
    {
        // create fieldset & column
        $fieldset = new XMLElement('fieldset');
        $fieldset->setAttribute('class', 'settings');
        $fieldset->appendChild(new XMLElement('legend', __('Geocoding')));
        $column = new XMLElement('div', null, array('class' => 'column'));

        // get the "google api key" from configuration & add input element
        $google_api_key = Symphony::Configuration()->get('google_api_key', 'geocoding');
        $form_geocoding['input'] = new XMLElement('label', __('Google API Key'), array('for' => 'settings-geocoding'));
        $form_geocoding['input']->appendChild(
            Widget::Input(
                'settings[geocoding][google_api_key]',
                $google_api_key,
                'text',
                array(
                    'id' => 'settings-geocoding'
                )
            )
        );
        // add help text
        $form_geocoding['help'] = new XMLElement('p', __('You will need a valid <a href="https://developers.google.com/maps/documentation/geocoding/get-api-key">Geocoding API Key</a> from Google in order to make geocoding work.'));
        $form_geocoding['help']->setAttribute('class', 'help');

        // append to column & fieldset
        $column->appendChildArray($form_geocoding);
        $fieldset->appendChild($column);

        // append to wrapper
        $context['wrapper']->appendChild($fieldset);
    }

    /*------------------------------------------------------------------------*/
    /*  UTILITIES
    /*------------------------------------------------------------------------*/

    /**
     * APPEND RESOURCES
     *
     * Add custom stylesheets or javascript to the backend
     *
     * @since version 2.0.0
     */

    public function appendResources(Array $context)
    {
        Administration::instance()->Page->addStylesheetToHead(
            URL . '/extensions/geocodingfield/assets/geocodingfield.publish.css',
            'screen',
            time(),
            false
        );
        return;
    }

    /**
     * REGISTER FIELD
     *
     * @since version 1.0.0
     */

    public function registerField($field)
    {
        self::$fields[] = $field;
    }

    /**
     * GET XPATH
     *
     * @since version 1.0.0
     */

	public function getXPath($entry)
	{
        $entry_xml = new XMLElement('entry');
        $data = $entry->getData();
        $fields = array();
        
        $entry_xml->setAttribute('id', $entry->get('id'));
        
        $associated = $entry->fetchAllAssociatedEntryCounts();

        if (is_array($associated) and !empty($associated)) {
            foreach ($associated as $section => $count) {
                $handle = Symphony::Database()->fetchVar('handle', 0, "
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
            
            $field = FieldManager::fetch($field_id);
            $field->appendFormattedElement($entry_xml, $values, false, null);
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

    /**
     * GEO RADIUS
     *
     * Modified from:
     * http://www.kevinbradwick.co.uk/developer/php/free-to-script-to-calculate-the-radius-of-a-coordinate-using-latitude-and-longitude
     *
     * @since version 1.0.0
     */

    public static function geoRadius($lat, $lng, $rad, $kilometers=false)
    {
        $radius = ($kilometers) ? ($rad * 0.621371192) : $rad;

        (float)$dpmLAT = 1 / 69.1703234283616;

        // Latitude calculation
        (float)$usrRLAT = $dpmLAT * $radius;
        (float)$latMIN = $lat - $usrRLAT;
        (float)$latMAX = $lat + $usrRLAT;

        // Longitude calculation
        (float)$mpdLON = 69.1703234283616 * cos($lat * (M_PI/180));
        (float)$dpmLON = 1 / $mpdLON; // degrees per mile longintude
        $usrRLON = $dpmLON * $radius;

        $lonMIN = $lng - $usrRLON;
        $lonMAX = $lng + $usrRLON;

        return array("lonMIN" => $lonMIN, "lonMAX" => $lonMAX, "latMIN" => $latMIN, "latMAX" => $latMAX);
    }

    /**
     * GEO DISTANCE
     *
     * Calculate distance between two lat/long pairs
     *
     * @since version 1.0.0
     */

    public static function geoDistance($lat1, $lon1, $lat2, $lon2, $unit)
    {
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

}
