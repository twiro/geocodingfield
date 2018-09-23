# Geocoding Field

A Symphony CMS extension that populates fields with geocoding information using the combined values of other fields.

## Dependencies

Geocoding Field uses the [Google Maps Platform][1] and requires an [Google API Key][2] for both the [Geocoding API][3] as well as the [Maps Static API][4].


## Installation

1. Upload the `/geocodingfield` folder in this archive to your Symphony `/extensions` folder.
2. Go to **System > Extensions** in your Symphony admin area.
3. Enable the extension by selecting the '**Field: Geocoding**', choose '**Enable**' from the '**With Selected…**' menu, then click '**Apply**'.
4. Go to **System > Preferences** and set up your **Google API Key**.
5. You can now add the '**Geocoding**' field to your sections.


## Configuration

When adding this field to a section, you will be asked to to define an `expression`. Like in the [Reflection Field][5] this `expression` gives you access to the values of other fields from the current entry via XPath – when saving an entry the generated output from the `expression` will be sent to the [Google Geocoding API][3] which will then try its best to return a matching set of coordinates.

For example, if you have a section with two fields "Country" and "City", you could use the Geocoding Field to get the matching coordinates by setting the expression to `{entry/country}, {entry/city}`.

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

## Data Source Output

The XML output of the field looks like this:

	<location latitude="51.6614" longitude="-0.40042"/>

The two attributes are the latitude/longitude of the location.

If you are filtering using the Geocoding Field using a "within" filter then you will see an additional `<distance>` element:

	<location latitude="51.6614" longitude="-0.40042">
		<distance from="51.6245572,-0.4674079" distance="3.8" unit="miles" />
	</location>

The `from` attribute is the latitude/longitude resolved from the DS filter (the origin), the `unit` shows either "km" or "miles" depending on what you use in your filter, and `distance` is the distance between your map marker and the origin.

## Credits

This extensions is heavily based on the [Reflection Field][5] and the [Map Location Field][6].

[1]: https://cloud.google.com/maps-platform/
[2]: https://developers.google.com/maps/documentation/geocoding/get-api-key
[3]: https://developers.google.com/maps/documentation/geocoding/
[4]: https://developers.google.com/maps/documentation/maps-static/
[5]: https://github.com/symphonists/reflectionfield/
[6]: https://github.com/symphonists/maplocationfield/

