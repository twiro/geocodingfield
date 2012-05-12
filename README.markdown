# Geocoding Field

* Version: 1.0
* Authors: Jonas Coch <jonas@klaftertief.de>
* Build Date: 2012-05-12
* Requirements: Symphony 2.3

## Installation

1. Upload the 'geocodingfield' folder in this archive to your Symphony 'extensions' folder.
2. Enable it by selecting the "Field: Geocoding", choose Enable from the with-selected menu, then click Apply.
3. You can now add the "Geocoding" field to your sections.

## Configuration

In the field settings you define an XPath to create a new value from the XML data of other fields which then gets geocoded.

For example, if you have a section with two fields "Country" and "City", you could use the Geocoding Field to get the location by setting its expression to `{entry/country}, {entry/city}`.

## Data Source Filtering

The field provides a single syntax for radius-based searches. Use the following as a DS filter:

	within DISTANCE UNIT of ORIGIN

* `DISTANCE` is an integer
* `UNIT` is the distance unit: `km`, `mile` or `miles`
* `ORIGIN` is the centre of the radius. Accepts either a latitude/longitude pair or an address

Examples:

	within 20 km of 10.545,-103.1
	within 1km of 1 West Street, Burleigh Heads, Australia
	within 500 miles of London

To make the filters dynamic, use the parameter syntax like any other filter. For example using querystring parameters:

	within {$url-distance} {$url-unit} of {$url-origin}

Attached to a page invoked as:

	/?distance=30&unit=km&origin=London,England

## Data Source XML result

The XML output of the field looks like this:

	<location latitude="51.6614" longitude="-0.40042"/>

The two attributes are the latitude/longitude of the location.

If you are filtering using the Geocoding Field using a "within" filter then you will see an additional `<distance>` element:

	<location latitude="51.6614" longitude="-0.40042">
		<distance from="51.6245572,-0.4674079" distance="3.8" unit="miles" />
	</location>

The `from` attribute is the latitude/longitude resolved from the DS filter (the origin), the `unit` shows either "km" or "miles" depending on what you use in your filter, and `distance` is the distance between your map marker and the origin.

## Credits

This extensions is heavily based on the [Reflection Field][1] and the [Map Location Field][2].


[1]: http://symphony-cms.com/download/extensions/view/20737/
[2]: http://symphony-cms.com/download/extensions/view/35942/

