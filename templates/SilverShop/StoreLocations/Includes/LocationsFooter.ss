<% if $StoreLocations %>
    <div class="store-footer">
        <% loop $StoreLocations %>
            <div class="store-footer__item">
                <p class="store-footer__name"><% if $Link %><a href="$Link">$Title</a><% else %>$Title<% end_if %></p>
                <address class="store-footer__address">$FormattedAddress</address>
                <% with $TodayHours %>
                    <p class="store-footer__today"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.Today 'Today' %>: $Text</p>
                <% end_with %>
                <% include SilverShop\StoreLocations\Includes\OpeningStatus %>
            </div>
        <% end_loop %>
    </div>
<% end_if %>
