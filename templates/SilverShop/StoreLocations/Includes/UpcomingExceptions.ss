<% if $UpcomingExceptions %>
    <div class="store-notices">
        <h3 class="store-notices__title"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.Deviating 'Deviating opening hours' %></h3>
        <ul class="store-notices__list">
            <% loop $UpcomingExceptions %>
                <li><strong>$Period</strong>: $Hours<% if $Reason %> <span class="store-notices__reason">($Reason)</span><% end_if %></li>
            <% end_loop %>
        </ul>
    </div>
<% end_if %>
