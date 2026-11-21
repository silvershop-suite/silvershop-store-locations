<?php

declare(strict_types=1);

namespace SilverShop\StoreLocations\Control;

use PageController;
use SilverShop\StoreLocations\Model\StoreLocation;
use SilverShop\StoreLocations\Page\StoreLocatorPage;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\Requirements;

/**
 * Renders the location list (index) and one location per URL segment ("/stores/delft").
 *
 * @mixin StoreLocatorPage
 */
class StoreLocatorPageController extends PageController
{
    private static array $allowed_actions = [
        'location',
    ];

    private static array $url_handlers = [
        '$URLSegment!' => 'location',
    ];

    /**
     * Include the module's small stylesheet (turn off when a theme styles the markup itself).
     */
    private static bool $include_css = true;

    /**
     * Include the script that keeps "open now" correct on cached pages.
     */
    private static bool $include_js = true;

    /**
     * Emit the module's own JSON-LD on the detail page when silvershop-suite/seo is not installed.
     */
    private static bool $emit_jsonld = true;

    protected ?StoreLocation $currentLocation = null;

    protected function init()
    {
        parent::init();
        self::requireAssets();
    }

    public static function requireAssets(): void
    {
        if (Config::inst()->get(self::class, 'include_css')) {
            Requirements::css('silvershop-suite/store-locations: client/css/store-locations.css');
        }
        if (Config::inst()->get(self::class, 'include_js')) {
            Requirements::javascript('silvershop-suite/store-locations: client/js/opening-status.js', ['defer' => true]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function location(HTTPRequest $request): array
    {
        $segment = (string) $request->param('URLSegment');
        $location = $segment !== '' ? StoreLocation::get()->filter('URLSegment', $segment)->first() : null;
        if (!$location instanceof StoreLocation) {
            $this->httpError(404);
        }
        $this->currentLocation = $location;

        return [
            'Location' => $location,
            'Title' => $location->Title,
            'MetaTitle' => $location->Title,
        ];
    }

    public function getCurrentLocation(): ?StoreLocation
    {
        return $this->currentLocation;
    }

    /**
     * Standalone JSON-LD for the current location, unless the seo module adds it to its graph.
     */
    public function LocationJsonLd(): ?DBHTMLText
    {
        $show = $this->currentLocation
            && self::config()->get('emit_jsonld')
            && !interface_exists('SilverShop\\Seo\\StructuredData\\StructuredDataProvider');
        if (!$show) {
            return null;
        }
        $json = str_replace('</', '<\/', $this->currentLocation->StructuredDataJSON());

        /** @var DBHTMLText $field */
        $field = DBHTMLText::create_field('HTMLFragment', '<script type="application/ld+json">' . $json . '</script>');
        return $field;
    }
}
