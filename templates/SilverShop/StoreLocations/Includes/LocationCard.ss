<article class="store-card">
    <% if $Image %>
        <div class="store-card__media store-card__media--photo">
            <% if $Link %><a href="$Link" tabindex="-1" aria-hidden="true">$Image.Fill(640,400)</a><% else %>$Image.Fill(640,400)<% end_if %>
        </div>
    <% else %>
        <div class="store-card__media store-card__media--empty" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M20 10c0 5-8 12-8 12s-8-7-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
        </div>
    <% end_if %>
    <div class="store-card__body">
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
            <% if $Link %><li class="store-card__action--primary"><a class="store-btn store-btn--secondary" href="$Link"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.Details 'Opening hours and details' %></a></li><% end_if %>
        </ul>
    </div>
</article>
