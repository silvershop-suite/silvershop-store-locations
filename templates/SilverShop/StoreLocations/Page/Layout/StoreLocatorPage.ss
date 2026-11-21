<div class="store-locator">
    <header class="store-locator__header">
        <h1 class="store-locator__title">$Title</h1>
        <% if $Content %><div class="store-locator__content">$Content</div><% end_if %>
    </header>

    <% if $Locations %>
        <ul class="store-locator__list">
            <% loop $Locations %>
                <li class="store-locator__item"><% include SilverShop\StoreLocations\Includes\LocationCard %></li>
            <% end_loop %>
        </ul>
    <% else %>
        <p class="store-locator__empty"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.NoLocations 'There are no locations yet.' %></p>
    <% end_if %>
</div>
