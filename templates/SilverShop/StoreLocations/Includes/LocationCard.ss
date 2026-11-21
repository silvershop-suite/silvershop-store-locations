<article class="store-card">
    <h2 class="store-card__title"><% if $Link %><a href="$Link">$Title</a><% else %>$Title<% end_if %></h2>
    <% include SilverShop\StoreLocations\Includes\OpeningStatus %>
    <address class="store-card__address">$FormattedAddress</address>
    <% with $TodayHours %>
        <p class="store-card__today">
            <span class="store-card__label"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.Today 'Today' %>:</span>
            $Text<% if $Reason %> <span class="store-card__reason">($Reason)</span><% end_if %>
        </p>
    <% end_with %>
    <ul class="store-card__actions">
        <% if $PhoneLink %><li><a href="$PhoneLink">$Phone</a></li><% end_if %>
        <li><a href="$DirectionsLink.ATT" target="_blank" rel="noopener"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.Directions 'Directions' %></a></li>
        <% if $Link %><li><a href="$Link"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.Details 'Opening hours and details' %></a></li><% end_if %>
    </ul>
</article>
