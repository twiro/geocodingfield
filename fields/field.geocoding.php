<?php

if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

require_once(CORE . '/class.cacheable.php');

class FieldGeocoding extends Field
{
    protected $_driver = null;
    protected static $ready = true;

    private $geocode_cache_expire = 60; // minutes
    private $filter_origin = array();

    /*------------------------------------------------------------------------*/
    /* DEFINITION
    /*------------------------------------------------------------------------*/

    /**
     * CONSTRUCT
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#__construct
     *
     * @since version 1.0.0
     */

    public function __construct()
    {
        parent::__construct();

        $this->_name = __('Geocoding');
        $this->_driver = Symphony::ExtensionManager()->create('geocodingfield');

        // Set defaults:
        $this->set('show_column', 'yes');
        $this->set('hide', 'no');
    }

    /**
     * CREATE TABLE
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#createTable
     *
     * @since version 1.0.0
     */

    public function createTable()
    {
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

    /**
     * CAN FILTER
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#canFilter
     *
     * @since version 1.0.0
     */

    public function canFilter()
    {
        return true;
    }

    /*------------------------------------------------------------------------*/
    /*  FIELD SETTINGS
    /*------------------------------------------------------------------------*/

    /**
     * DISPLAY SETTINGS PANEL
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#displaySettingsPanel
     *
     * @since version 1.0.0
     */

    public function displaySettingsPanel(XMLElement &$wrapper, $errors = null)
    {
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
        $help->setValue(__('Use XPath to access other fields: <code>{entry/field-one} static text {entry/field-two}</code>.'));

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

    /**
     * COMMIT
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#commit
     *
     * @since version 1.0.0
     */

    public function commit()
    {
        if (!parent::commit()) return false;

        $id = $this->get('id');
        $handle = $this->handle();

        if ($id === false) return false;

        $fields = array(
            'field_id'       => $id,
            'expression'     => $this->get('expression'),
            'hide'           => $this->get('hide')
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

    /*------------------------------------------------------------------------*/
    /*  HELPER
    /*------------------------------------------------------------------------*/

    /**
     * GET API KEY STRING
     *
     * Get the google api key from the configuration and format it as a string
     * for the use in api-calls. (geocoding api, static maps api)
     *
     * https://developers.google.com/maps/documentation/geocoding/
     * https://developers.google.com/maps/documentation/static-maps/
     *
     * @since version 2.0.0
     */

    private function __getApiKeyString()
    {
        $apiKeyString = '';
        $apiKey = Symphony::Configuration()->get('google_api_key', 'geocoding');
        if(!empty($apiKey)) {
            $apiKeyString = "&key=".$apiKey;
        }
        return $apiKeyString;
    }

    /*------------------------------------------------------------------------*/
    /*  PUBLISH
    /*------------------------------------------------------------------------*/

    /**
     * DISPLAY PUBLISH PANEL
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#displayPublishPanel
     *
     * Uses the google static maps api to show map-images:
     * https://developers.google.com/maps/documentation/static-maps/
     *
     * @since version 1.0.0
     */

    public function displayPublishPanel(XMLElement &$wrapper, $data = null, $flagWithError = null, $fieldnamePrefix = null, $fieldnamePostfix = null, $entry_id = null)
    {
        if (class_exists('Administration') && Administration::instance()->Page) {
            Administration::instance()->Page->addStylesheetToHead(URL . '/extensions/geocodingfield/assets/geocodingfield.publish.css', 'screen', 246);
        }

        $elementName = $this->get('element_name');

        if ($this->get('hide') != 'yes') {

            // get api key string
            $apiKeyString = $this->__getApiKeyString();

            $frame = new XMLElement('div', null, array('class' => 'frame inline'));

            // Map
            $wrapperMap = new XMLElement('div', null, array('class' => 'map'));
            $map = new XMLElement('img', null, array(
                'alt' => '',
                'src' => sprintf(
                    'https://maps.google.com/maps/api/staticmap?zoom=13&size=200x200&scale=2&markers=color:red|size:mid|%s'.$apiKeyString,
                    implode(',', array($data['latitude'], $data['longitude']))
                )
            ));

            // Latitude & Longitude
            $wrapperLongLat = new XMLElement('div', null, array('class' => 'coordinates'));
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
            $wrapperLongLat->appendChild($latitude);
            $wrapperLongLat->appendChild($longitude);

            $wrapperMap->appendChild($map);
            $frame->appendChild($wrapperMap);
            $frame->appendChild($wrapperLongLat);

            $wrapper->appendChild(new XMLElement('label', $this->get('label'), array('class' => 'label')));
            $wrapper->appendChild($frame);

        } else {

            $wrapper->addClass('irrelevant');

        }
    }

    /**
     * PREPARE TABLE VALUE
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#prepareTableValue
     *
     * Uses the google static maps api to show map-images:
     * https://developers.google.com/maps/documentation/static-maps/
     *
     * @since version 1.0.0
     */

    public function prepareTableValue($data, XMLElement $link = null, $entry_id = null)
    {
        if (empty($data)) return;

        $url = sprintf(
            'https://www.google.com/maps/place/%s',
            implode(',', array($data['latitude'], $data['longitude']))
        );

        $wrapper = new XMLElement('a', null, array('class' => 'map-table','href' => $url));

        // get api key string
        $apiKeyString = $this->__getApiKeyString();

        $img = new XMLElement('img', null, array(
            'alt' => '',
            'src' => sprintf(
                'https://maps.google.com/maps/api/staticmap?zoom=12&size=150x150&scale=2&markers=color:red|size:mid|%s'.$apiKeyString,
                implode(',', array($data['latitude'], $data['longitude']))
            )
        ));

        $wrapper->appendChild($img);

        return $wrapper;
    }

    /*------------------------------------------------------------------------*/
    /*  DATA INPUT
    /*------------------------------------------------------------------------*/

    /**
     * CHECK POST FIELD DATA
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#checkPostFieldData
     *
     * @since version 1.0.0
     */

    public function checkPostFieldData($data, &$message, $entry_id = null)
    {
        $this->_driver->registerField($this);
        return self::__OK__;
    }

    /**
     * PROCESS RAW FIELD DATA
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#processRawFieldData
     *
     * @since version 1.0.0
     */

    public function processRawFieldData($data, &$status, &$message = null, $simulate = false, $entry_id = null)
    {
        $status = self::__OK__;
        return array(
            'latitude' => null,
            'longitude' => null
        );
    }

    /**
     * COMPILE
     *
     * This function generates the address string from other fields, geocodes the
     * address and saves the field data in the database.
     *
     * @since version 1.0.0
     */

    public function compile($entry)
    {
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
            } else {
                $replacements[$match] = '';
            }
        }

        // Apply replacements:
        $value = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $expression
        );

        // Geocode the compiled address
        $geocode = $this->__geocodeAddress($value);
        if ($geocode) $geocode = explode(',', $geocode);
        $lat = trim($geocode[0]);
        $lng = trim($geocode[1]);

        self::$ready = true;

        // Update the entry data
        $result = Symphony::Database()->update(
            array(
                'latitude'	=> $lat,
                'longitude'	=> $lng
            ),
            "tbl_entries_data_{$field_id}",
            "`entry_id` = '{$entry_id}'"
        );
    }

    /**
     * GEOCODE ADDRESS
     *
     * This function is adopted from the extension "Map Location Field" (3.4.5):
     * https://github.com/symphonists/maplocationfield/blob/3.4.5/fields/field.maplocation.php#L61
     *
     * @since version 1.0.0
     */

    private function __geocodeAddress($address, $can_return_default=true)
    {
        $coordinates = null;

        // prepare chache
        $cache_id = md5('geocoding_' . $address);
        $cache = new Cacheable(Symphony::Database());
        $cachedData = $cache->check($cache_id);

        // if no data has been cached
        if(!$cachedData) {

            include_once(TOOLKIT . '/class.gateway.php');

            // get api key string
            $apiKeyString = $this->__getApiKeyString();

            // request coordinates from google maps api
            $ch = new Gateway;
            $ch->init();
            $ch->setopt('URL', 'https://maps.googleapis.com/maps/api/geocode/json?address='.urlencode($address).$apiKeyString);
            $response = json_decode($ch->exec());

            if($response->status === 'OK') {
                $coordinates = $response->results[0]->geometry->location;
            } else {
                return false;
            }

            if ($coordinates && is_object($coordinates)) {
                $cache->write($cache_id, $coordinates->lat . ', ' . $coordinates->lng, $this->geocode_cache_expire); // cache lifetime in minutes
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
        elseif ($can_return_default) {
            return '0, 0';
        }
    }

    /*------------------------------------------------------------------------*/
    /*  DATA SOURCE OUTPUT
    /*------------------------------------------------------------------------*/

    /**
     * APPEND FORMATTED ELEMENT
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#appendFormattedElement
     *
     * @since version 1.0.0
     */

    public function appendFormattedElement(XMLElement &$wrapper, $data, $encode = false, $mode = NULL, $entry_id = NULL)
    {
        if (!self::$ready) return;

        $element = new XMLElement($this->get('element_name'), null, array(
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ));

        if ( $this->filter_origin['latitude'] ) {
            $distance = new XMLElement('distance');
            $distance->setAttribute('from', $this->filter_origin['latitude'] . ',' . $this->filter_origin['longitude']);
            $distance->setAttribute('distance', Extension_GeocodingField::geoDistance($this->filter_origin['latitude'], $this->filter_origin['longitude'], $data['latitude'], $data['longitude'], $this->filter_origin['unit']));
            $distance->setAttribute('unit', ($this->filter_origin['unit'] == 'k') ? 'km' : 'miles');
            $element->appendChild($distance);
        }

        $wrapper->appendChild($element);
    }

    /*------------------------------------------------------------------------*/
    /*  FILTERING
    /*------------------------------------------------------------------------*/

    /**
     * BUILD DS RETRIEVAL SQL
     *
     * http://www.getsymphony.com/learn/api/2.5.0/toolkit/field/#buildDSRetrievalSQL
     *
     * This function is adopted from the extension "Map Location Field" (3.4.5):
     * https://github.com/symphonists/maplocationfield/blob/3.4.5/fields/field.maplocation.php#L275
     *
     * @since version 1.0.0
     */

    function buildDSRetrievalSQL($data, &$joins, &$where, $andOperation=false)
    {

        // Symphony by default splits filters by commas. We want commas, so
        // concatenate filters back together again putting commas back in
        $data = implode(',', $data);

        /*
            Filter examples:
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

            $this->filter_origin['latitude'] = $lat;
            $this->filter_origin['longitude'] = $lng;
            $this->filter_origin['unit'] = $unit[0];

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
